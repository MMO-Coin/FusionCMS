# Install with Docker Compose

New to Docker? Start with the [Windows, macOS and Linux setup guide](docker-quickstart.md). This page covers database networking, persistence, backups and updates in more detail.

Docker Compose is an optional way to run FusionCMS. It uses the existing web installer. Normal Apache, Nginx and shared-hosting installations work as before and do not need Docker.

The CMS image includes Apache, PHP 8.3, the required PHP extensions and Composer dependencies. You still need an existing WoW server with reachable auth, characters and world databases. This setup does not install a game server. Swarm and multiple CMS replicas are outside this setup's scope.

## Before you start

Use Docker Engine or Docker Desktop in Linux container mode, with Docker Compose v2.24 or newer. On Windows, Docker Desktop needs its Linux backend running. Run commands from the repository root.

Both guides use the project name `fusioncms` with `-p fusioncms`. If you already installed under a different name, use that original name in every command instead. `docker compose ls` lists existing projects.

Build from a clean source checkout. The build excludes local database credentials, `fusion.php`, `owner.php`, `.env` files, uploads and backups. Other tracked files, including SMTP and module configuration, are part of the image. Keep production secrets out of the source checkout used for builds.

Copy `docker/.env.example` to `docker/.env`:

```sh
cp docker/.env.example docker/.env
```

In PowerShell, use `Copy-Item docker/.env.example docker/.env`. Edit the copy with your preferred editor. Git ignores this file.

The default address is `http://localhost:8080`. The port binds to loopback, so you can complete installation before exposing the site. On a remote server, use an SSH tunnel or a restricted reverse proxy to reach the installer.

## Use an existing CMS database

Create an empty CMS database and a database user with the permissions needed to create and update its tables. Then run:

```sh
docker compose -p fusioncms --env-file docker/.env up -d --build --wait
```

Open `http://localhost:8080` and complete the normal installer. Enter your existing database hosts, ports and credentials. The database password fields in `docker/.env` can stay empty for this method.

## Use the optional MariaDB container

Set separate, long random values for `FUSION_DB_PASSWORD` and `FUSION_DB_ROOT_PASSWORD` in `docker/.env`. The example uses single quotes so passwords containing `$` or `#` keep their literal values.

```sh
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml up -d --build --wait
```

Open `http://localhost:8080` and enter these CMS database settings:

| Installer field | Value |
| --- | --- |
| Host | `db` |
| Port | `3306` |
| Database | `FUSION_DB_NAME`, default `fusioncms` |
| Username | `FUSION_DB_USER`, default `fusioncms` |
| Password | Your `FUSION_DB_PASSWORD` value, without its surrounding quotes |

Enter your existing game databases and realm settings in the remaining installer steps. The bundled database is for the CMS. It has no host port published and does not create game databases.

Compose variables configure the containers. The web installer saves the CMS connection settings. Changing `FUSION_DB_PASSWORD` later does not change an existing MariaDB user's password or the CMS connection file. Update those together when rotating credentials. MariaDB's initialization variables apply when its data volume is empty. See the [official MariaDB image documentation](https://hub.docker.com/_/mariadb).

Use the same `--env-file`, `-f` arguments and project name for subsequent commands. Commands below that include `compose.database.yaml` assume you chose bundled MariaDB. Omit that file when using an external CMS database.

## Reach your game databases

`localhost` inside the CMS container refers to that container. Use one of these addresses instead:

- For a database on the Docker host, use `host.docker.internal`. The Compose file adds the host gateway mapping for Linux. The database must listen on an interface reachable from Docker and allow the CMS database user to connect from that network.
- For another server, use its reachable hostname or IP address and database port.
- For game services already in Docker, attach the CMS to their existing network and use their service names and container ports.

For the last option, create a local `compose.override.yaml` beside `compose.yaml`:

```yaml
services:
  cms:
    networks:
      - default
      - game
networks:
  game:
    external: true
    name: your-existing-game-network
```

Compose automatically reads `compose.override.yaml` when no `-f` files are specified. With explicit files, add `-f compose.override.yaml` to every command. See [Compose networking](https://docs.docker.com/compose/how-tos/networking/) for connections between projects. Realm SOAP/console addresses must also be reachable from the CMS container.

## What persists

Compose creates project-scoped named volumes:

| Volume | Contents |
| --- | --- |
| `config` | Application configuration, installer database settings and owner settings |
| `writable` | Uploads, backups, logs, caches and `install/.lock` |
| `settings` | Module and theme configuration, module activation manifests and the defaults tracking file |
| `database` | Bundled MariaDB data, when enabled |

Restarting, replacing containers and running `docker compose down` retain these volumes. **`docker compose down --volumes` deletes them.** Keep the same project name and checkout directory when redeploying, or explicitly select the original project with `-p`. A different project name creates a separate installation.

On startup, Docker seeds missing configuration from the image. New images refresh files that still match their original defaults. Files changed by the installer or an administrator stay intact. If an edited file also changed in the new image, startup logs print `Review changed image defaults for ...`. Compare that file with the copy under `/opt/fusioncms/defaults` and merge the needed changes before returning the site to service. Database migrations still follow the normal [upgrade instructions](../UPGRADING.md).

Application code comes from the image. Add custom modules, themes and assets to your build checkout, then rebuild. Settings for included modules and themes persist, but code uploaded or changed inside a running container does not survive replacement. Use image rebuilds for code updates rather than the admin panel's updater or ZIP module installer.

## Back up and restore

Back up the CMS database and all three CMS volumes together. Keep game database backups through your existing server backup process. The example below writes into a local `backup` directory; its archives contain credentials and should be stored privately, outside Git.

Stop CMS writes before taking the backup:

```sh
mkdir backup
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml stop cms
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump -u"$MARIADB_USER" --single-transaction --routines --triggers "$MARIADB_DATABASE" > /tmp/fusioncms-backup.sql'
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml cp db:/tmp/fusioncms-backup.sql backup/cms.sql
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml run --rm --no-deps --entrypoint sh -v "${PWD}/backup:/backup" cms -c 'tar -czf /backup/config.tar.gz -C /var/www/html/application/config . && tar -czf /backup/writable.tar.gz -C /var/www/html/writable . && tar -czf /backup/settings.tar.gz -C /var/lib/fusioncms .'
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml exec -T db rm /tmp/fusioncms-backup.sql
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml start cms
```

For an external CMS database, use that server's database backup tools and omit the `db` commands. Save a private copy of `docker/.env`, your Compose overrides, the source revision and custom source files alongside the backup.

Restore into a separate project first. Build its image from the saved source revision, keep `cms` stopped, and restore the archives:

```sh
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml build cms
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml run --rm --no-deps --entrypoint sh -v "${PWD}/backup:/backup:ro" cms -c 'tar -xzf /backup/config.tar.gz -C /var/www/html/application/config && tar -xzf /backup/writable.tar.gz -C /var/www/html/writable && tar -xzf /backup/settings.tar.gz -C /var/lib/fusioncms'
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml up -d --wait db
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml cp backup/cms.sql db:/tmp/fusioncms-restore.sql
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" mariadb -u"$MARIADB_USER" "$MARIADB_DATABASE" < /tmp/fusioncms-restore.sql'
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml exec -T db rm /tmp/fusioncms-restore.sql
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml up -d --wait cms
```

The restored CMS connection file must match the restored database credentials and reachable hostnames. Check login, uploads and realm connections before moving traffic to the restored site.

## Updates and hosting

Take a backup, update the clean build checkout, then rebuild and replace the CMS container:

```sh
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml build --pull cms
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml up -d --no-deps --force-recreate --wait cms
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml logs --tail=100 cms
```

The PHP image follows the current 8.3 Apache Bookworm tag. Composer checks that the PHP version meets this repository's minimum. Rebuild with `--pull` to pick up base image security releases. Keep MariaDB on the 11.4 branch unless you have planned and tested a database upgrade.

After an application update, clear application caches through the admin panel's cache manager so generated CSS, JavaScript and compiled templates reflect the new code.

For a public site, put the HTTP container behind your HTTPS reverse proxy. Keep loopback binding for a proxy on the host. For a proxy in Docker, use a shared network. Have the proxy overwrite forwarded headers, including `X-Forwarded-Proto`, and configure the CMS's trusted proxy settings for that proxy. `FUSION_BIND_ADDRESS=0.0.0.0` publishes HTTP on all host interfaces if you need direct access.

Apache blocks direct requests to configuration, Docker files, private writable folders and PHP uploads. Public uploaded images and generated CSS/JavaScript under `writable/cache/data/minify` remain accessible. The health check confirms that Apache accepts connections; use the installer checks and application pages to diagnose database or configuration problems.

## Run the Docker checks

With Docker running and Node.js 20 or newer installed:

```sh
node tests/docker/smoke.cjs
node tests/docker/smoke.cjs --external
```

Each run creates a unique disposable Compose project, uses generated test passwords, and removes only that project's containers and volumes. It does not read `docker/.env`. The checks call the real installer endpoints, verify installer permissions and PHP/Apache requirements, complete installation, then replace the containers and repeat a down/up cycle. They compare saved configuration and upload hashes, check CMS and auth database rows, and test access restrictions for private files and executable uploads.

The fixture checks realm database connectivity but does not run a game server or insert a live realm. `php tests/docker_state_test.php` separately checks configuration seeding, restart behavior and upgrades with edited files. Linux CI also checks the symlinks used for persistent module and theme settings. The GitHub workflow runs both Docker database modes on Linux.
