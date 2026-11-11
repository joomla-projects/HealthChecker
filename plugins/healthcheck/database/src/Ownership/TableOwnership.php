<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Healthcheck.Database
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Healthcheck\Database\Ownership;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Works out which `#__` tables belong to Joomla core and to the installed extensions, from their manifests and SQL files.
 *
 * @since  __DEPLOY_VERSION__
 */
final class TableOwnership
{
    /**
     * Constructor
     *
     * @param   string  $siteRoot   The site root folder (JPATH_ROOT)
     * @param   string  $adminRoot  The administrator folder (JPATH_ADMINISTRATOR)
     *
     * @since   __DEPLOY_VERSION__
     */
    public function __construct(private readonly string $siteRoot, private readonly string $adminRoot)
    {
    }

    /**
     * Lowercased `#__` names of the core tables plus the tables named by the extensions' install and update SQL.
     *
     * @param   iterable<object>  $extensions  Objects with type, element, folder and client_id
     *
     * @return  string[]
     *
     * @since   __DEPLOY_VERSION__
     */
    public function accounted(iterable $extensions): array
    {
        $names = array_fill_keys(CoreTables::NAMES, true);

        foreach ($extensions as $extension) {
            foreach ($this->sqlFiles($extension) as $file) {
                $sql = @file_get_contents($file);

                if ($sql === false) {
                    continue;
                }

                foreach (SqlTableNames::fromSql($sql) as $name) {
                    $names[strtolower($name)] = true;
                }
            }
        }

        return array_map('strval', array_keys($names));
    }

    /**
     * The install SQL files and the update files of the manifest's schema paths, or of the
     * `sql/updates/<driver>` folders when the manifest names none.
     *
     * @param   object  $extension  The extension row
     *
     * @return  string[]  Real paths of the `.sql` files inside the extension folder
     *
     * @since   __DEPLOY_VERSION__
     */
    private function sqlFiles(object $extension): array
    {
        $type    = (string) ($extension->type ?? '');
        $element = (string) ($extension->element ?? '');
        $folder  = (string) ($extension->folder ?? '');
        $client  = (int) ($extension->client_id ?? 0);

        if ($element === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $element) || !preg_match('/^[A-Za-z0-9_.-]*$/', $folder)) {
            return [];
        }

        switch ($type) {
            case 'component':
                $base     = $this->adminRoot . '/components/' . $element;
                $manifest = $base . '/' . (str_starts_with($element, 'com_') ? substr($element, 4) : $element) . '.xml';
                break;
            case 'plugin':
                if ($folder === '') {
                    return [];
                }

                $base     = $this->siteRoot . '/plugins/' . $folder . '/' . $element;
                $manifest = $base . '/' . $element . '.xml';
                break;
            case 'module':
                $base     = ($client === 1 ? $this->adminRoot : $this->siteRoot) . '/modules/' . $element;
                $manifest = $base . '/' . $element . '.xml';
                break;
            default:
                return [];
        }

        $realBase = realpath($base);

        $realManifest = $realBase === false ? false : realpath($manifest);

        if ($realBase === false || $realManifest === false || !str_starts_with($realManifest, $realBase . DIRECTORY_SEPARATOR) || !is_file($realManifest)) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_file($realManifest, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return [];
        }

        $files = [];

        foreach ($xml->xpath('//install/sql/file') ?: [] as $node) {
            $this->addFile($files, $realBase, (string) $node);
        }

        $folders = array_map('strval', $xml->xpath('//update/schemas/schemapath') ?: []);

        // Without a schemapath, core's DatabaseModel reads <base>/sql/updates/<driver>: do the same.
        if ($folders === []) {
            foreach (glob($realBase . '/sql/updates/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $folders[] = substr($dir, \strlen($realBase) + 1);
            }
        }

        foreach ($folders as $folder) {
            $dir = $this->inside($realBase, $folder);

            if ($dir === null || !is_dir($dir)) {
                continue;
            }

            foreach (glob($dir . '/*.sql') ?: [] as $path) {
                $this->addFile($files, $realBase, substr($path, \strlen($realBase) + 1));
            }
        }

        return array_values($files);
    }

    /**
     * Add a manifest-relative path when it is a .sql file inside the extension folder.
     *
     * @param   array   $files     Collected real paths, keyed by path
     * @param   string  $realBase  Real path of the extension folder
     * @param   string  $relative  Path from the manifest
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    private function addFile(array &$files, string $realBase, string $relative): void
    {
        $path = $this->inside($realBase, $relative);

        if ($path !== null && is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'sql') {
            $files[$path] = $path;
        }
    }

    /**
     * Resolve a path relative to the extension folder, refusing anything that leaves it.
     *
     * @param   string  $realBase  Real path of the extension folder
     * @param   string  $relative  Path relative to it
     *
     * @return  string|null  The real path when it exists inside the base folder
     *
     * @since   __DEPLOY_VERSION__
     */
    private function inside(string $realBase, string $relative): ?string
    {
        $relative = trim($relative);

        if ($relative === '') {
            return null;
        }

        $real = realpath($realBase . '/' . $relative);

        if ($real === false || !str_starts_with($real, $realBase . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }
}
