# Install FusionCMS with Docker

This guide walks you through a fresh FusionCMS installation on Windows, macOS or Linux. Docker supplies the web server, PHP and its dependencies. You finish setup in your browser using the normal FusionCMS installer.

You need an existing WoW server with reachable auth, characters and world databases, plus the game account that will become the website owner. Choose the bundled MariaDB option below if you do not already have a database for the website.

For an existing website, read the [backup and update guide](docker.md) before changing its setup.

## 1. Install Docker and Git

Use a 64-bit operating system that meets Docker's current requirements. The steps below cover common desktop and server systems. FusionCMS runs in Linux containers, including when the host is Windows or macOS.

| Your system | Setup route |
| --- | --- |
| Windows desktop | Docker Desktop with its WSL 2 backend |
| macOS, Apple silicon or Intel | Docker Desktop for your Mac's chip |
| Ubuntu or Debian | Docker Engine and the Compose plugin |
| Fedora | Docker Engine from Docker's Fedora repository |
| CentOS Stream or RHEL | Docker Engine from the matching Docker repository |
| Raspberry Pi | A 64-bit Debian or Ubuntu installation on compatible ARM64 hardware |
| Other Linux distributions | Your distribution's Docker instructions, with Compose v2.24 or newer |

If Docker and Git already work, go straight to [step 2](#2-check-that-docker-is-ready). The Linux package commands below are for fresh systems. For a machine with an older Docker installation or Podman, use the linked official instructions to handle package conflicts first.

### Windows

1. Check the supported Windows versions and hardware requirements on the [Docker Desktop download page](https://docs.docker.com/desktop/setup/install/windows-install/).
2. If WSL is not installed, open PowerShell as administrator and run:

   ```powershell
   wsl --install
   ```

   Restart Windows when asked and finish the WSL setup. To update an existing WSL installation, run `wsl --update`.
3. Install Docker Desktop using the WSL 2 backend. Open Docker Desktop and wait until its engine is running. Use Linux containers for FusionCMS.
4. Install [Git for Windows](https://git-scm.com/downloads/win), then open a new PowerShell window. Use PowerShell for the remaining steps.

Docker Desktop does not support Windows Server. For a Windows Server host, run Docker Engine in a Linux VM and follow that Linux distribution's steps inside the VM. See [Docker's Windows requirements](https://docs.docker.com/desktop/setup/install/windows-install/#system-requirements).

### macOS

1. Download [Docker Desktop for Mac](https://docs.docker.com/desktop/setup/install/mac-install/). Select Apple silicon or Intel to match the chip shown in **Apple menu > About This Mac**.
2. Open the downloaded `.dmg`, drag Docker to Applications, then open Docker. Wait until its engine is running.
3. Open Terminal and run `git --version`. If Git is missing, install Apple's command line tools:

   ```sh
   xcode-select --install
   ```

   Finish the installation, then reopen Terminal. Use Terminal for the remaining steps.

### Linux

Open a terminal on the machine where you will host FusionCMS. Expand the instructions for your distribution. Use a user with `sudo` access; if you are logged in as root, omit `sudo`.

<details>
<summary>Ubuntu</summary>

Use a release listed in [Docker's Ubuntu requirements](https://docs.docker.com/engine/install/ubuntu/). Add Docker's official package repository, then install Docker, Compose and Git:

```sh
sudo apt-get update
sudo apt-get install -y ca-certificates curl git
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
sudo tee /etc/apt/sources.list.d/docker.sources > /dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
```

</details>

<details>
<summary>Debian</summary>

Use a release listed in [Docker's Debian requirements](https://docs.docker.com/engine/install/debian/):

```sh
sudo apt-get update
sudo apt-get install -y ca-certificates curl git
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
sudo tee /etc/apt/sources.list.d/docker.sources > /dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/debian
Suites: $(. /etc/os-release && echo "$VERSION_CODENAME")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
```

</details>

<details>
<summary>Fedora</summary>

These commands use the current DNF 5 syntax. Check [Docker's Fedora instructions](https://docs.docker.com/engine/install/fedora/) for supported releases:

```sh
sudo dnf install -y git dnf5-plugins
sudo dnf config-manager addrepo --from-repofile https://download.docker.com/linux/fedora/docker-ce.repo
sudo dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
```

</details>

<details>
<summary>CentOS Stream or RHEL</summary>

Install Git and the repository tools:

```sh
sudo dnf install -y git dnf-plugins-core
```

Add the repository for **your distribution only**:

```sh
# CentOS Stream
sudo dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
```

```sh
# RHEL
sudo dnf config-manager --add-repo https://download.docker.com/linux/rhel/docker-ce.repo
```

Then install and start Docker:

```sh
sudo dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
```

Check the current requirements for [CentOS Stream](https://docs.docker.com/engine/install/centos/) or [RHEL](https://docs.docker.com/engine/install/rhel/) before installing.

</details>

For Linux Mint, Kali, Rocky Linux, AlmaLinux, Arch, openSUSE and other distributions, follow your distribution's Docker installation instructions or the [Docker installation index](https://docs.docker.com/engine/install/). Derivatives may need a different repository or release codename. Once Docker Engine and Compose v2.24 or newer work, continue below.

On a Raspberry Pi, use a supported 64-bit Debian or Ubuntu release and its matching instructions. This guide does not cover 32-bit Pi installations.

Linux may require `sudo` before Docker commands. You can keep using `sudo docker ...`, or follow Docker's [post-install instructions](https://docs.docker.com/engine/install/linux-postinstall/) to give your user access. Membership in the `docker` group grants administrative access to the machine. If you change group membership, sign out and back in before continuing.

## 2. Check that Docker is ready

Run these commands in PowerShell on Windows, or a terminal on macOS/Linux. On Linux, prefix Docker commands with `sudo` if your user needs it.

```sh
git --version
docker version
docker compose version
docker info --format '{{.OSType}}'
```

`docker version` should show both Client and Server information. Compose must be v2.24 or newer, and the last command should print `linux`. Keep Docker Desktop running on Windows and macOS.

## 3. Download FusionCMS

Use a new folder for this installation:

```sh
git clone --branch main https://github.com/MMO-Coin/FusionCMS.git fusioncms-docker
cd fusioncms-docker
```

Create your local settings file. On **Windows PowerShell**:

```powershell
Copy-Item docker/.env.example docker/.env
notepad docker/.env
```

On **macOS or Linux**:

```sh
cp docker/.env.example docker/.env
```

Open `docker/.env` in a text editor. A terminal editor such as `nano docker/.env` also works if installed. If you save with Notepad, keep the exact filename `.env`, without adding `.txt`.

All remaining commands run from the `fusioncms-docker` folder. They use the project name `fusioncms`. Keep that name when restarting, backing up or updating this installation so Docker uses the same saved volumes.

## 4. Choose your website database

Pick **one** of these options. Both use your existing WoW databases in the web installer.

### Option A: Include MariaDB

Use this option if you want Docker to create the website database for you. Set these values in `docker/.env`:

```dotenv
FUSION_BIND_ADDRESS=127.0.0.1
FUSION_HTTP_PORT=8080
TZ=UTC
FUSION_DB_NAME=fusioncms
FUSION_DB_USER=fusioncms
FUSION_DB_PASSWORD='REPLACE_WITH_A_LONG_RANDOM_PASSWORD'
FUSION_DB_ROOT_PASSWORD='REPLACE_WITH_A_DIFFERENT_LONG_RANDOM_PASSWORD'
```

Replace both password placeholders with different passwords and save the file. Single quotes preserve characters such as `$` and `#`. Keep a private copy of the passwords.

Build and start FusionCMS and MariaDB:

```sh
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml up -d --build --wait
```

The first build downloads images and installs dependencies, so it can take several minutes. When the command finishes successfully, continue to step 5. MariaDB creates the CMS database automatically; it does not create game databases.

### Option B: Use an existing database server

Create an empty MySQL/MariaDB database for the website and a user allowed to create, read, update and remove tables and records in that database. Use a separate CMS database from your auth, characters and world databases.

Keep the default address and port in `docker/.env`. Its database password fields can stay empty for this option. You will enter the actual database credentials in the web installer.

Start FusionCMS:

```sh
docker compose -p fusioncms --env-file docker/.env -f compose.yaml up -d --build --wait
```

The database server must accept connections from the CMS container. Use `host.docker.internal` for a database on the Docker host, or the reachable hostname/IP for a separate database server. Inside a container, `localhost` points to that container.

## 5. Open the website

If Docker runs on your own computer, open [http://localhost:8080](http://localhost:8080) in your browser.

If Docker runs on a VPS, the default port listens only on that server. Open a terminal on **your own computer** and replace `USER` and `SERVER_IP` below:

```sh
ssh -L 8080:127.0.0.1:8080 USER@SERVER_IP
```

Keep that SSH session open, then visit [http://localhost:8080](http://localhost:8080) on your computer. If your local port 8080 is busy, use `ssh -L 8081:127.0.0.1:8080 USER@SERVER_IP` and open `http://localhost:8081` instead.

## 6. Complete the web installer

For **Option A**, enter these CMS database settings:

| Installer field | Value |
| --- | --- |
| Host | `db` |
| Port | `3306` |
| Database | `fusioncms`, or your `FUSION_DB_NAME` |
| Username | `fusioncms`, or your `FUSION_DB_USER` |
| Password | Your `FUSION_DB_PASSWORD`, without surrounding quotes |

For **Option B**, enter your external CMS database's host, port, database name, username and password.

Then enter your WoW auth database and realm settings, choose the correct emulator, and select your existing owner account. The game databases and SOAP/console endpoint must be reachable from the CMS container. For game services in another Docker project, see [how to join their Docker network](docker.md#reach-your-game-databases).

After installation, sign in with the owner account. Your configuration, uploads, installer lock, module/theme settings and bundled database data are saved in Docker volumes.

## Put the website online

For a public website, configure your domain and an HTTPS reverse proxy that forwards to the CMS container. See the [hosting instructions](docker.md#updates-and-hosting). Publishing a container's HTTP port alone does not set up HTTPS.

## Check, stop or start the website

Run only the command you need from the option you installed.

For **Option A**:

```sh
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml ps
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml logs --tail=100 cms db
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml stop
docker compose -p fusioncms --env-file docker/.env -f compose.yaml -f compose.database.yaml up -d --wait
```

For **Option B**:

```sh
docker compose -p fusioncms --env-file docker/.env -f compose.yaml ps
docker compose -p fusioncms --env-file docker/.env -f compose.yaml logs --tail=100 cms
docker compose -p fusioncms --env-file docker/.env -f compose.yaml stop
docker compose -p fusioncms --env-file docker/.env -f compose.yaml up -d --wait
```

`stop` keeps your containers and saved data. A normal `down` also retains the named volumes. **Adding `--volumes` to `down` deletes the saved installation data.** Take a [backup](docker.md#back-up-and-restore) before upgrades or moving to another server.

## If something does not work

| What you see | What to check |
| --- | --- |
| Docker cannot connect to its daemon | Open Docker Desktop, or start Docker Engine with `sudo systemctl start docker` on Linux. |
| Permission denied on the Docker socket | Use `sudo` for Docker commands on Linux, or finish the linked post-install setup and sign in again. |
| `docker compose` is not recognized | Install the Compose plugin on Linux, or update Docker Desktop. Use `docker compose`, with a space. |
| Compose says a database password is missing | For Option A, save both password values in `docker/.env`. Check that the file is not named `.env.txt`. |
| Port 8080 is already in use on the Docker host | Change `FUSION_HTTP_PORT` in `docker/.env`, rerun your start command and use that port in the browser or SSH tunnel. |
| The installer cannot connect to a database | Check its host, port, database name, user permissions and network access. A host database bound only to loopback may need a reachable interface. |
| You changed a password in `.env`, but login to MariaDB still fails | Initialization variables only apply to an empty database volume. Update the database user's password and the saved CMS connection together. |
| The installer appears again after a previous successful install | Check that you are using the original project name, Compose files and Docker context. A different project uses different volumes. |
| A build fails on an older or unusual CPU | Check that the PHP and MariaDB base images support that architecture. Use a supported 64-bit OS and include the error when reporting the issue. |

Docker OS requirements change over time. The platform links above point to Docker's current documentation. The FusionCMS Docker integration checks run on Linux amd64; these instructions do not mean every listed host OS or CPU has been separately tested by this project.
