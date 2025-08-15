Joomla Health Checker
====================

***** UNDER CONSTRUCTION *****

Build Status

| Drone-CI                                                                                                                                 | AppVeyor                                                                                                                                                           | PHP                                                                           | Node                                                                                 | npm                                                                             |
|------------------------------------------------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------|--------------------------------------------------------------------------------------|---------------------------------------------------------------------------------|
| [![Build Status](https://ci.joomla.org/api/badges/joomla/joomla-cms/status.svg?branch=5.4-dev)](https://ci.joomla.org/joomla/joomla-cms) | [![Build status](https://ci.appveyor.com/api/projects/status/ru6sxal8jmfckvjc/branch/5.4-dev?svg=true)](https://ci.appveyor.com/project/release-joomla/joomla-cms) | [![PHP](https://img.shields.io/badge/PHP-V8.1.0-green)](https://www.php.net/) | [![node-lts](https://img.shields.io/badge/Node-V20.0-green)](https://nodejs.org/en/) | [![npm](https://img.shields.io/badge/npm-v10.1.0-green)](https://nodejs.org/en/) |

Overview
---------------------
* With continuously changing standards and improvements there is a need to identify breaking changes well before migration/upgrade so that remedial steps can be taken by the site owner, web agencies and third-party extension developers.
Being able to identify third-party extensions that are not adhering to the latest coding standards or those that will be affected by a future Joomla core change that is marked for deprecation at the point of installation is of huge benefit. It will stop the end-of-the-line situation that some extensions can cause and allow better scrutiny of the extension to pick the right one for the website owner's project.

* Template overrides can have old copy-paste code that gets leftover time and breaks on migration/update, but this is also a security issue. 

* The Health Checker creates a customizable dashboard for managing the health of a Joomla site. The Joomla Health Checker Component is an administrator component designed to improve code quality, identify deprecated code, and assist in migration issues long before a site needs to be migrated. It utilises PHPStan and other tools to analyse the Joomla core and third-party extensions.
  
* The concept behind the Joomla Health Checker is simple. While the existing update checker focuses solely on whether your extensions are current, the Health Checker takes a 360-degree view of your website's wellbeing.

Joomla Health Checker Core Features
-----------------------------------
- Code Analysis
- Administrator Interface
- Exclusion Management
- Override and Layout
- Compatibility Checks

Joomla Health Checker Advanced Features
-----------------------------------
- Reporting System
- Notification System
- Performance Optimisation
- Security Analysis

Technical Requirements
-----------------------------------
- PHPStan Integration
- Joomla Version Compatibility
- Extension API
- Database

User Interface
-----------------------------------
- Dashboard
- Scan Configuration
- Results View
- Settings
  

Interesting in helping?
--------------------
* Join us on MatterMost: [6.0 Health Checker](https://joomlacommunity.cloud.mattermost.com/main/channels/60-health-checker)

To check what's cooking
--------------------
Download the code, build the instance and install on your local server.
Once installed, go to:
`htpps://yoursite.local/administrator/index.php?option=com_admin&view=healthcheck`

Copyright
---------------------
* (C) 2005 Open Source Matters, Inc. <https://www.joomla.org>
* Distributed under the GNU General Public License version 2 or later
* See [License details](https://docs.joomla.org/Special:MyLanguage/Joomla_Licenses)
