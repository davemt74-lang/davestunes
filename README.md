# Dave's Tunes

Dave's Tunes is a modular music platform and spatial listening experience.

## Architecture

The platform is being built in staged foundations:

1. Application, user, and artist identity infrastructure
2. Canonical music catalog
3. Library, commerce, and entitlements
4. Playback runtime
5. Spatial Music Desktop
6. Z-scroll scene/effect engine
7. Template Builder and Scene Navigator

The repository is intentionally dependency-light and targets PHP 8.1+, MySQL 8, and modern browsers.


## Fresh installation

1. Upload the application files to a PHP 8.1+ web host with MySQL 8.
2. Open `/install.php` in the browser.
3. Enter the MySQL host, port, database name, username and password.
4. Create the first Dave's Tunes user. This account becomes the initial platform administrator.
5. The installer creates all application tables and generates the local application secret automatically. No external API keys are required.
6. After the first user exists, `/install.php` locks itself and only shows a link to sign in.

The installer writes `config.php`, which is excluded from Git. The database itself must already exist and the supplied database user must have permission to create tables.


## Soft Launch RC1

Current release: `1.0.0-rc1` (`soft-launch`).

The soft launch intentionally focuses on the core music experience already implemented: accounts, artist identities, catalog publishing, personal library, Music Desktop, digital turntable, featured content, authored album/artist experiences, and the first-run web installer. Commerce is not part of RC1 and the public copy does not advertise purchasing.

The Desktop surface and turntable visuals are generated with CSS. No background-image pack is required for the default Midnight Desk template.
