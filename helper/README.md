# Privileged helper

`rc-sieve-refilter` runs on the Dovecot/Pigeonhole host (not the Roundcube web
host, unless they're the same box). It's invoked via `sudo` by the system
user PHP-FPM runs Roundcube as, and does the actual work of shelling out to
Dovecot Pigeonhole's `sieve-filter` — see the header comment in the script
for exactly what it validates and why.

Verified by hand against a live Dovecot 2.3.16/Pigeonhole install
(`maildir:/var/vmail/%d/%n`, sieve scripts at
`/var/vmail/sieve/%d/%n/.dovecot.sieve`, namespace separator `/`), using
disposable test folders/messages only. Two things confirmed that shaped the
script:

- `sieve-filter` needs root (or equivalent) to read another user's
  `vmail`-owned, `0600` mailbox/script files — a normal unprivileged user
  can't do this itself.
- Its own default `discard-action` is `keep` — a script's `discard` action
  does **not** remove the message from the source folder unless you
  explicitly pass `move <mailbox>` or `expunge` as extra positional
  arguments (each a separate argv token, not a quoted string). `fileinto`
  actions are unaffected by this and always take effect. This is why the
  helper exposes an explicit `discard-policy` argument (`keep` default,
  `trash`, `expunge`) rather than silently picking one.

## Installation

On the mail server, as root:

```bash
install -o root -g root -m 0700 rc-sieve-refilter /usr/local/sbin/rc-sieve-refilter
touch /var/log/sievereplay-helper.log
chown root:root /var/log/sievereplay-helper.log
chmod 0644 /var/log/sievereplay-helper.log
```

Then add a sudoers rule scoped to exactly this script, for whichever system
user your Roundcube instance's PHP-FPM pool runs as (find it from the pool's
`user =` setting — e.g. `apache`, `www-data`, `php-fpm`):

```
# /etc/sudoers.d/sievereplay -- validate with `visudo -cf` before installing
<php-fpm-user> ALL=(root) NOPASSWD: /usr/local/sbin/rc-sieve-refilter
```

`chmod 0440` the sudoers file. This grants that user root only for this one
script with no argument wildcarding — the script's own strict input
validation is what stops it being used against anyone else's mailbox; the
sudoers rule alone does not scope that, so the calling PHP code must never
pass anything but the authenticated Roundcube session's own username.
