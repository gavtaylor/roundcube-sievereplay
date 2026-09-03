# sievereplay

A [Roundcube](https://roundcube.net/) webmail plugin that lets you re-run your existing Sieve filters (managed via the core `managesieve` plugin) against messages already sitting in a mailbox — not just new mail as it arrives.

> **This project was built using agentic AI development** (Claude Code). We're disclosing this up front because we know not everyone's comfortable with AI-authored code in something touching their inbox — review it accordingly before you install it.

## Status

**Working, verified end-to-end from a live browser session** against a real Roundcube + Dovecot/Pigeonhole instance — settings page, folder picker, discard-policy selector, preview, and apply were all driven from the actual UI, with the result independently confirmed via `doveadm` afterward. Edge cases covered from the browser too: an empty folder, a folder with a message matching no active rule, a folder name containing spaces, a folder name with non-ASCII characters, and the `expunge` discard-policy (confirmed the message was permanently removed, not moved to Trash). Testing throughout used disposable test folders/messages only — real mail was never touched.

Results are now parsed into a short summary ("Checked N message(s): M would move, D would be discarded" plus a capped list of only the affected messages) instead of dumping sieve-filter's raw text — tested against a real multi-thousand-message INBOX (simulate/read-only), where that's a handful of lines instead of a six-figure wall of text.

Several real bugs were found and fixed along the way:
- `allowed_folders()` called a method that returns `void` in this Roundcube version (`storage_init()` vs `get_storage()`).
- Folder names weren't round-tripped through IMAP's wire encoding (modified UTF-7) correctly: the dropdown showed raw `&...-` escapes instead of decoded text, and the helper/`sieve-filter` call failed on a UTF7-IMAP folder name since `sieve-filter` wants plain UTF-8.
- The output-parsing regex had a classic greedy-`.*`-under-`/s` bug that collapsed multiple messages' blocks into one.
- The settings page had no button styling or spacing at all (a plain `<button>` has no CSS class applied anywhere in Roundcube) and was missing Elastic's own `propform` table class — fixed to match the native look, following the pattern already established by this account's `markasphishing` plugin, plus an intro/hint explaining the workflow and a distinct nav icon.

This has, so far, only ever run against one instance (Dovecot 2.3.16/Pigeonhole) by one admin. Tagged versions and a broader "tested against" statement will follow once it's run somewhere else too.

## Why

Sieve only ever executes at delivery time, inside the mail server's LDA/LMTP pipeline. Roundcube's `managesieve` plugin (Settings → Filters) only *manages* Sieve scripts over the ManageSieve protocol (RFC 5804) — there's no ManageSieve command, and no Roundcube hook, to run a script against mail that's already in a folder. This is a genuine, long-standing gap: see upstream Roundcube feature requests [#6787](https://github.com/roundcube/roundcubemail/issues/6787) and [#7656](https://github.com/roundcube/roundcubemail/issues/7656), both open and unaddressed.

## How it works

Rather than reimplementing Sieve parsing/matching in PHP (which would inevitably drift from your real, live delivery-time filters), this plugin drives Dovecot Pigeonhole's own [`sieve-filter`](https://doc.dovecot.org/main/core/man/sieve-filter.1.html) tool — the tool Pigeonhole itself ships specifically for (re-)running a compiled Sieve script against messages already in a mailbox, with a safe simulate-only mode and an explicit execute mode.

```
Roundcube plugin (this repo)
   → resolves the logged-in username from the session (never from client input)
   → validates the requested folder against that user's own IMAP folder list
   → shells out to sievereplay_helper (typically `sudo -n /usr/local/sbin/rc-sieve-refilter`)
   → helper (helper/rc-sieve-refilter) validates its inputs, resolves that
     user's active Sieve script itself, and runs:
       sieve-filter -u <user> [-e -W] -C <active-script> <folder> [move Trash|expunge]
   → result (dry-run preview, or execute summary) shown back in the settings page
```

Both the `sieve-filter` command itself and the helper script have been tested by hand
against a live instance — see [`helper/README.md`](helper/README.md) for the deployment
steps (sudoers rule, log file) and what was confirmed, including an important nuance:
`sieve-filter`'s own default leaves `discard`-ed messages exactly where they are even in
execute mode, unless you explicitly ask it to move or expunge them — which is why this
plugin surfaces that as its own explicit setting rather than picking a default for you.

## Settings page

Settings → Sieve Replay, a single page:

- **Folder** — any folder in your own mailbox, shown with its normal display name (special folders localized, non-ASCII names decoded) even though the value sent to the server is IMAP's raw wire encoding.
- **When you Apply, if a rule discards a message** — Move it to Trash (default) or Delete it permanently. This only controls messages a rule explicitly `discard`s — it has no effect on messages a rule moves, which always move for real the moment you Apply, regardless of this setting. That distinction is called out directly in the on-page hint text, since "Apply" can otherwise read as if it were still a dry run.
- **Preview (dry run)** — always safe, never changes anything, whatever the discard-policy is set to. Shows a short summary ("Checked N message(s): M would move, D would be discarded") plus which specific messages would be affected (capped at 200 for a very large folder), with sieve-filter's full raw output available behind a "Show full output" toggle for anything the summary doesn't cover.
- **Apply** — disabled until a preview has run successfully; asks for confirmation, then performs the real run and reports what actually happened.

Both buttons show a spinner and disable themselves while a request is in flight, and any unexpected failure (a fatal sieve-filter error, output the parser doesn't recognise) is shown as an explicit error rather than a falsely cheerful summary.

## Installation

The mail-server side (`helper/rc-sieve-refilter` + its sudoers rule) is set up and tested
per [`helper/README.md`](helper/README.md). The Roundcube plugin side:

```bash
cd /path/to/roundcube/plugins
git clone https://github.com/gavtaylor/roundcube-sievereplay.git sievereplay
cd sievereplay
composer install --no-dev   # if the plugin has any dependencies
```

Add `sievereplay` to `$config['plugins']` in your Roundcube `config.inc.php`, then copy `config.inc.php.dist` and set `sievereplay_helper` to the command that invokes the mail-server-side helper (e.g. `sudo -n /usr/local/sbin/rc-sieve-refilter` if Roundcube and Dovecot share a host).

## Configuration reference

See [`config.inc.php.dist`](config.inc.php.dist) for the full list of options with defaults and descriptions.

## License

[GPL-3.0-or-later](LICENSE), matching Roundcube core and the wider plugin ecosystem this integrates with.
