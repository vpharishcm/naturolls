# Automatic deployment with GitHub

After this one-time setup, deploying is just pushing to the `main` branch:

1. GitHub runs every backend and frontend test against MariaDB 10.11, the same as the server.
2. If any test fails, nothing is deployed.
3. If all pass, it takes a backup on the server, pauses the app for a few seconds, uploads the new code and app, updates the database, and checks the live site.

Your `.env`, product photos, backups and logs on the server are never touched. Database passwords never go to GitHub.

While the app is paused, new orders are saved on the device and sync when it returns, as in any short outage.

## What goes in Git

| Pushed | Never pushed |
| --- | --- |
| `backend/` source, migrations, tests | `dist-hosting/`, which includes `FIRST_LOGIN.txt` and the server's `.env` |
| `frontend/` source | Any `.env` file, except `.env.example` |
| `deploy/`, `docs/`, `.github/` | Backups (`*.enc`), database dumps (`*.sql`) |
| `README.md`, `.gitignore` | `vendor/`, `node_modules/`, `frontend/dist/` (rebuilt each time) |
| | SSH keys (`naturolls_deploy`, `*.pem`, `*.key`) |

The rules are in `.gitignore` at the top of the project.

## 1. Check the server has tar

In cPanel **Terminal**:

```bash
which tar
```

It should print a path. The deploy uploads files as one tar file over SSH, so rsync is not needed.

## 2. Create a deploy key

On your computer, in the `E:\naturolls` folder:

```bash
ssh-keygen -t ed25519 -C naturolls-deploy -f naturolls_deploy -N ""
```

This makes `naturolls_deploy` (private, secret) and `naturolls_deploy.pub` (public). Both are already ignored by Git.

In cPanel, open **SSH Access** › **Manage SSH Keys** › **Import Key**. Name it `naturolls_deploy`, paste the contents of `naturolls_deploy.pub` into the public key box, and import. Back in the list, press **Manage** beside it and **Authorize**.

Find your SSH port: MilesWeb shows it on the SSH Access page or in its welcome email. Many accounts use a custom port rather than 22. Then test from your computer:

```bash
ssh -i naturolls_deploy -p PORT pozswnqh@naturolls.harishsteels.com "echo ok"
```

It should print `ok`. If SSH is refused, ask MilesWeb to enable SSH access for your account.

Record the server's identity, which GitHub uses to be sure it is talking to your real server:

```bash
ssh-keyscan -p PORT naturolls.harishsteels.com
```

## 3. Create the GitHub repository

On github.com, create a new repository and make it **Private**. Do not add a README or .gitignore there.

On your computer, in `E:\naturolls`:

```bash
git branch -M main
```

```bash
git add .
```

```bash
git status
```

Check the list. It must **not** include `dist-hosting`, `FIRST_LOGIN.txt`, any `.env` except `.env.example`, `naturolls_deploy`, or `vendor`. If any appear, stop and fix `.gitignore` first.

```bash
git commit -m "Naturolls POS"
```

```bash
git remote add origin https://github.com/YOUR-NAME/YOUR-REPO.git
```

```bash
git push -u origin main
```

This first push only runs the tests, because the server details are not saved yet. Open the repository's **Actions** tab to watch it.

## 4. Save the server details in GitHub

In the repository: **Settings** › **Secrets and variables** › **Actions**.

On the **Secrets** tab, add:

| Name | Value |
| --- | --- |
| `SSH_HOST` | `naturolls.harishsteels.com` |
| `SSH_PORT` | Your SSH port |
| `SSH_USER` | `pozswnqh` |
| `SSH_PRIVATE_KEY` | The whole contents of `naturolls_deploy`, including the BEGIN and END lines |
| `SSH_KNOWN_HOSTS` | The whole output of the `ssh-keyscan` command |

On the **Variables** tab, add:

| Name | Value |
| --- | --- |
| `APP_DIR` | `/home/pozswnqh/naturolls_app` |
| `WEB_DIR` | `/home/pozswnqh/naturolls.harishsteels.com` |
| `SITE_URL` | `https://naturolls.harishsteels.com` |
| `PHP_BIN` | `/usr/local/bin/php` |

Optional: in **Settings** › **Environments** › **production**, add yourself as a **Required reviewer**. Each deploy then waits for you to press **Approve** in the Actions tab.

Once saved, delete `naturolls_deploy` from your computer or keep it somewhere safe. Anyone with it can sign in to your server.

## 5. Deploy

Open **Actions** › **Test and deploy** › **Run workflow**, or push any change to `main`. A green tick on both jobs means it is live.

## Every change after that

```bash
git add .
```

```bash
git commit -m "Describe the change"
```

```bash
git push
```

## If a deploy fails

- **Test job fails:** nothing changed on the server. Fix the test and push again.
- **Backup step fails:** nothing changed. Check **Settings › Backups** in the app for the message.
- **A later step fails:** the app is resumed automatically. A backup was taken just before, so see the [backup guide](BACKUP.md) if the database needs restoring. Check the step's log in Actions for the message.
- **Site check fails:** open the site and check `naturolls_app/storage/logs` on the server.

## Things the deploy does not do

- It never imports `database-start.sql` and never changes `.env`. To change a setting such as `APP_URL`, edit `.env` on the server.
- It does not copy backups off the server. Keep downloading one regularly from **Settings › Backups**.
