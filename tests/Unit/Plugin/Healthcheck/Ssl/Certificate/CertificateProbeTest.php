<?php

/**
 * @package     Joomla.UnitTest
 * @subpackage  Healthcheck.Ssl
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tests\Unit\Plugin\Healthcheck\Ssl\Certificate;

use Joomla\Plugin\Healthcheck\Ssl\Certificate\CertificateProbe;
use Joomla\Tests\Unit\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Test class for the SSL certificate probe (no outbound network)
 *
 * @since  __DEPLOY_VERSION__
 */
#[TestDox('The SSL certificate probe')]
class CertificateProbeTest extends UnitTestCase
{
    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function targetProvider(): array
    {
        return [
            'plain https' => ['https://www.example.org/', ['https' => true, 'host' => 'www.example.org', 'port' => 443]],
            'subfolder'   => ['https://example.org/joomla/', ['https' => true, 'host' => 'example.org', 'port' => 443]],
            'custom port' => ['https://example.org:8443/', ['https' => true, 'host' => 'example.org', 'port' => 8443]],
            'uppercase'   => ['HTTPS://Example.ORG/', ['https' => true, 'host' => 'example.org', 'port' => 443]],
            'ipv6'        => ['https://[2001:db8::1]/', ['https' => true, 'host' => '2001:db8::1', 'port' => 443]],
            'http'        => ['http://example.org/', ['https' => false, 'host' => 'example.org', 'port' => 443]],
            'no host'     => ['/joomla/', null],
            'garbage'     => ['http://', null],
        ];
    }

    /**
     * @param   string  $url       Root URL
     * @param   ?array  $expected  Target
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('targetProvider')]
    #[TestDox('derives host, port and scheme from the site root URL')]
    public function testTarget(string $url, ?array $expected): void
    {
        $this->assertSame($expected, CertificateProbe::target($url));
    }

    /**
     * @return  array
     *
     * @since   __DEPLOY_VERSION__
     */
    public static function subjectProvider(): array
    {
        return [
            'common name'           => [['subject' => ['CN' => 'example.org']], 'example.org'],
            'common name as array'  => [['subject' => ['CN' => ['', 'first.example', 'second.example']]], 'first.example'],
            'empty name uses SAN'   => [['subject' => ['CN' => ''], 'extensions' => ['subjectAltName' => 'DNS:a.example, DNS:b.example']], 'a.example'],
            'SAN skips non-DNS'     => [['subject' => [], 'extensions' => ['subjectAltName' => 'IP Address:10.0.0.1, DNS:c.example']], 'c.example'],
            'no name at all'        => [['subject' => ['O' => 'Org']], ''],
            'SAN without DNS entry' => [['extensions' => ['subjectAltName' => 'IP Address:10.0.0.1, email:a@example.org']], ''],
        ];
    }

    /**
     * @param   array   $info      An openssl_x509_parse() array
     * @param   string  $expected  The name
     *
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[DataProvider('subjectProvider')]
    #[TestDox('finds the name a certificate was issued for')]
    public function testSubjectName(array $info, string $expected): void
    {
        $this->assertSame($expected, CertificateProbe::subjectName($info));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('reads validity dates and issuer from a certificate')]
    public function testParse(): void
    {
        $key  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr  = openssl_csr_new(['commonName' => 'example.test', 'organizationName' => 'Test Issuer'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 30);

        $parsed = CertificateProbe::parse($cert);

        $this->assertSame('Test Issuer', $parsed['issuer']);
        $this->assertSame('example.test', $parsed['subject']);
        $this->assertSame(30 * 86400, $parsed['validTo'] - $parsed['validFrom']);
        $this->assertEqualsWithDelta(time(), $parsed['validFrom'], 120);

        openssl_x509_export($cert, $pem);

        $this->assertSame($parsed, CertificateProbe::parse($pem));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('falls back to the issuer common name without an organisation')]
    public function testParseIssuerFallback(): void
    {
        $key  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr  = openssl_csr_new(['commonName' => 'only-cn.test'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 1);

        $this->assertSame('only-cn.test', CertificateProbe::parse($cert)['issuer']);
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('returns null for something that is not a certificate')]
    public function testParseGarbage(): void
    {
        $this->assertNull(CertificateProbe::parse('not a certificate'));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('turns PHP stream warnings into a one-line reason')]
    public function testCleanReason(): void
    {
        $this->assertSame(
            'SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed',
            CertificateProbe::cleanReason("stream_socket_enable_crypto(): SSL operation failed with code 1. OpenSSL Error messages:\nerror:0A000086:SSL routines::certificate verify failed")
        );
        $this->assertSame('Connection refused', CertificateProbe::cleanReason('  Connection refused '));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('uses the generic handshake reason when a warning is empty')]
    public function testReasonFrom(): void
    {
        $this->assertSame(CertificateProbe::REASON_HANDSHAKE, CertificateProbe::reasonFrom(''));
        $this->assertSame(CertificateProbe::REASON_HANDSHAKE, CertificateProbe::reasonFrom("  stream_socket_enable_crypto():  \n "));
        $this->assertSame('Connection refused', CertificateProbe::reasonFrom('Connection refused'));
    }

    /**
     * @return  void
     *
     * @since   __DEPLOY_VERSION__
     */
    #[TestDox('reports a refused connection as a failure, not an exception')]
    public function testRefused(): void
    {
        $result = (new CertificateProbe())->probe('127.0.0.1', 1, 1);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['trusted']);
        $this->assertNotSame('', $result['reason']);
        $this->assertSame('', $result['subject']);
        $this->assertNull($result['validTo']);
        $this->assertEqualsWithDelta(time(), $result['checkedAt'], 5);
    }
}
