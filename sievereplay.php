<?php

/**
 * sievereplay
 *
 * A plugin that lets a user re-run their existing Sieve filters (managed via
 * the core `managesieve` plugin) against messages already sitting in a
 * mailbox, instead of only ever applying at delivery time.
 *
 * This plugin does not reimplement Sieve matching itself. Sieve only ever
 * executes server-side, inside Dovecot Pigeonhole's LDA/LMTP plugin -- there
 * is no ManageSieve (RFC 5804) command, and no Roundcube hook, that runs a
 * script against existing mail. Pigeonhole ships a purpose-built tool for
 * exactly that, `sieve-filter`, which this plugin drives via a small
 * privileged helper on the mail server (see config.inc.php.dist and
 * README.md) -- so the real delivery-time engine does the matching, with
 * full fidelity to the user's actual live filters.
 *
 * @author Gavin Taylor
 *
 * Copyright (C) Gavin Taylor
 *
 * This program is a Roundcube (https://roundcube.net) plugin.
 * For more information see README.md.
 * For configuration see config.inc.php.dist.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see https://www.gnu.org/licenses/.
 */
class sievereplay extends rcube_plugin
{
    public $task = 'settings';

    private $rcube;

    #[\Override]
    public function init()
    {
        $this->rcube = rcmail::get_instance();
        $this->load_config('config.inc.php.dist');
        $this->load_config();

        $this->add_texts('localization/', true);

        $this->register_action('plugin.sievereplay', [$this, 'settings_page']);
        $this->register_action('plugin.sievereplay.preview', [$this, 'preview']);
        $this->register_action('plugin.sievereplay.run', [$this, 'run']);

        $this->add_hook('settings_actions', [$this, 'settings_actions']);
    }

    public function settings_actions($args)
    {
        $args['actions'][] = [
            'action' => 'plugin.sievereplay',
            'class' => 'sievereplay',
            'label' => 'sievereplay.title',
            'title' => 'sievereplay.title',
            'domain' => 'sievereplay',
        ];

        return $args;
    }

    public function settings_page()
    {
        $this->rcube->output->set_pagetitle($this->gettext('title'));
        $this->include_script('sievereplay.js');
        $this->include_stylesheet($this->local_skin_path() . '/sievereplay.css');
        $this->register_handler('plugin.body', [$this, 'render_form']);
        $this->rcube->output->send('plugin');
    }

    /**
     * What happens to a message a rule `discard`s, when actually applied.
     * sieve-filter's own default ('keep', leaving the message exactly
     * where it is, confirmed by hand against a live instance) isn't
     * offered here for now -- not needed yet.
     */
    private const DISCARD_POLICIES = ['trash', 'expunge'];

    public function render_form()
    {
        $folders = $this->allowed_folders();

        $folder_select = new html_select(['name' => 'sievereplay_folder', 'id' => 'sievereplay-folder']);
        foreach ($folders as $folder) {
            // Label is human-readable (decodes UTF7-IMAP, localizes special
            // folder names) -- found by hand-testing against a folder with
            // non-ASCII characters, which otherwise showed up as raw
            // "&ANw-n&AO8-..." IMAP-UTF7 in the dropdown. The submitted
            // *value* stays the raw folder name the server actually uses.
            $folder_select->add(rcmail_action::localize_foldername($folder, true), $folder);
        }

        $discard_select = new html_select(['name' => 'sievereplay_discard', 'id' => 'sievereplay-discard']);
        foreach (self::DISCARD_POLICIES as $policy) {
            $discard_select->add($this->gettext('discard_' . $policy), $policy);
        }

        // 'propform' is core Elastic's own class for a settings field
        // table -- without it the rows render unstyled (no column split,
        // no row spacing), which is why the page looked cramped before.
        $table = new html_table(['cols' => 2, 'class' => 'propform']);
        $table->add('title', html::label('sievereplay-folder', $this->gettext('folder')));
        $table->add(null, $folder_select->show());
        $table->add('title', html::label('sievereplay-discard', $this->gettext('discardpolicy')));
        $table->add(null, $discard_select->show('trash'));

        $intro = html::p(null, rcube::Q($this->gettext('intro')))
            . html::p(['class' => 'hint'], rcube::Q($this->gettext('introhint')));

        // Starts empty; sievereplay.js fills it in and the .sievereplay-
        // result:empty CSS rule keeps it invisible until then -- html::div()
        // silently drops a 'hidden' attribute (not in its allowed-attribute
        // list), found by inspecting the rendered page.
        return html::div(['class' => 'sievereplay-settings'],
            $intro . $table->show() . $this->action_buttons()
            . html::div(['id' => 'sievereplay-result', 'class' => 'sievereplay-result'], ''));
    }

    private function action_buttons()
    {
        // 'button'/'button mainaction' are core Elastic's own button
        // classes (a plain <button> with no class renders as an
        // unstyled, unspaced rectangle -- that's what made the page look
        // wrong before). Apply is the actual effectful action, so it gets
        // the emphasized style and leads; Preview is the plain secondary
        // button, even though it's the one you click first.
        return html::p(['class' => 'formbuttons footerleft'],
            html::tag('button', ['id' => 'sievereplay-apply', 'type' => 'button', 'class' => 'button mainaction', 'disabled' => 'disabled'], $this->gettext('apply'))
            . html::tag('button', ['id' => 'sievereplay-preview', 'type' => 'button', 'class' => 'button'], $this->gettext('preview')));
    }

    /**
     * AJAX: dry-run (simulate) the active Sieve script against the chosen
     * folder and report what it would do, without changing anything.
     */
    public function preview()
    {
        $this->dispatch(false);
    }

    /**
     * AJAX: actually run the active Sieve script against the chosen folder
     * (-e -W), after the user has confirmed a preview.
     */
    public function run()
    {
        $this->dispatch(true);
    }

    private function dispatch(bool $execute)
    {
        $folder = rcube_utils::get_input_string('folder', rcube_utils::INPUT_POST);
        $allowed = $this->allowed_folders();

        if (!in_array($folder, $allowed, true)) {
            $this->rcube->output->command('display_message', $this->gettext('errorfolder'), 'error');
            $this->rcube->output->send();
            return;
        }

        if (!$this->user_permitted()) {
            $this->rcube->output->command('display_message', $this->gettext('errorpermission'), 'error');
            $this->rcube->output->send();
            return;
        }

        // Only meaningful in execute mode -- the helper accepts it for
        // simulate too, but sieve-filter's own simulate output doesn't
        // vary by discard-policy since nothing is actually written.
        $discard_policy = rcube_utils::get_input_string('discard', rcube_utils::INPUT_POST) ?: 'trash';
        if (!in_array($discard_policy, self::DISCARD_POLICIES, true)) {
            $discard_policy = 'trash';
        }

        $result = $this->invoke_helper($folder, $execute, $discard_policy);
        $result = array_merge($result, $result['exit'] === -1
            ? ['lines' => [$result['output']], 'ok' => false]
            : $this->summarize($result['output'], $execute));

        // Safety net: a nonzero exit the parser didn't recognise as a
        // Fatal: line (unexpected sieve-filter output format, a crash,
        // ...) must never be reported as success -- fall back to showing
        // the raw output rather than a misleadingly cheerful summary.
        if ($result['exit'] !== 0 && $result['ok']) {
            $result['lines'] = [$this->gettext('resulterror') . ' ' . $result['output']];
            $result['ok'] = false;
        }

        if ($execute && $this->rcube->config->get('sievereplay_debug')) {
            rcube::write_log('sievereplay', sprintf(
                'user=%s folder=%s execute=1 discard=%s exit=%d',
                $this->rcube->get_user_name(),
                $folder,
                $discard_policy,
                $result['exit']
            ));
        }

        $this->rcube->output->command('plugin.sievereplay_result', $result);
        $this->rcube->output->send();
    }

    /**
     * Only folders belonging to the logged-in user's own account are ever
     * offered -- this is what stops a user targeting anyone else's mail,
     * on top of the helper itself being scoped to the session username.
     */
    private function allowed_folders(): array
    {
        return $this->rcube->get_storage()->list_folders();
    }

    private function user_permitted(): bool
    {
        $admins = $this->rcube->config->get('sievereplay_admins');

        if (empty($admins)) {
            return true;
        }

        return in_array($this->rcube->get_user_name(), (array) $admins, true);
    }

    /**
     * Turns sieve-filter's plain-text output into a short list of ready-
     * to-display lines instead of dumping the raw text into the page --
     * a folder the size of a real INBOX (thousands of messages) would
     * otherwise produce an unusable wall of "(none) / implicit keep" text,
     * when nearly all of it is uninteresting. Two output shapes are
     * handled, confirmed by hand against a live instance:
     *
     * Simulate mode, one block per message (subjects available, so this
     * is where the interesting "what would this do" detail comes from):
     *   >> Filtering message:
     *     ID: <...>  Date: ...  Size: ...  Subject: ...
     *   Performed actions:
     *     * discard | * store message in folder: X | (none)
     *   Implicit keep:
     *     * store message in folder: X | (none)
     *
     * Execute mode, one "Info:" line per message (no subject available --
     * by the time Apply runs the user has already seen the per-message
     * detail from Preview, so this only needs to confirm counts matched):
     *   sieve-filter(root): Info: sieve: msgid=<...>: fileinto action:
     *     stored mail into mailbox 'X'
     *   sieve-filter(root): Info: sieve: msgid=<...>: discard action: ...
     *   sieve-filter(root): Info: sieve: msgid=<...>: left message in
     *     mailbox 'X'
     *
     * A Fatal: line, or output matching neither shape, is never silently
     * dropped -- it's surfaced as an error line, with the raw text always
     * still available in the UI for anything this parser gets wrong.
     */
    private function summarize(string $raw, bool $execute): array
    {
        $counts = ['kept' => 0, 'moved' => 0, 'discarded' => 0, 'other' => 0];
        $changes = [];

        if (preg_match_all('/^sieve-filter\([^)]*\): Fatal:\s*(.*)$/m', $raw, $m)) {
            return ['lines' => array_map(fn ($e) => $this->gettext('resulterror') . ' ' . $e, $m[1]), 'ok' => false];
        }

        if ($execute) {
            // Info: lines carry no subject, so just tally counts.
            if (preg_match_all('/^sieve-filter\([^)]*\): Info: sieve: msgid=.*?: (?P<detail>.*)$/m', $raw, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $entry) {
                    if (str_starts_with($entry['detail'], 'discard action')) {
                        $counts['discarded']++;
                    } elseif (str_starts_with($entry['detail'], 'fileinto action')) {
                        $counts['moved']++;
                    } elseif (str_starts_with($entry['detail'], 'left message in mailbox')) {
                        $counts['kept']++;
                    } else {
                        $counts['other']++;
                    }
                }
            }
        } elseif (preg_match_all(
            // [^\n]* (not .*) for the single-line fields -- under /s (needed
            // so actions/keep can span the multi-line action blocks), a
            // greedy .* matches across newlines too and swallowed straight
            // through into the next message's block entirely, confirmed by
            // testing against real multi-message output where it collapsed
            // N blocks into 1.
            '/>>\s*Filtering message:\s*\n+\s*ID:\s*(?P<id>[^\n]*)\n\s*Date:\s*[^\n]*\n\s*Size:\s*[^\n]*\n\s*Subject:\s*(?P<subject>[^\n]*)\n+Performed actions:\s*\n+(?P<actions>.*?)\n+Implicit keep:\s*\n+(?P<keep>.*?)(?=\n{2,}>>\s*Filtering message:|\z)/s',
            $raw,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $entry) {
                $actions = trim($entry['actions']);
                $keep = trim($entry['keep']);
                $subject = trim($entry['subject']);

                if (stripos($actions, 'discard') !== false) {
                    $counts['discarded']++;
                    $changes[] = $subject . ' — ' . $this->gettext('resultwoulddiscard');
                } elseif (preg_match('/store message in folder:\s*(.*)/', $actions, $fm)) {
                    $counts['moved']++;
                    $changes[] = $subject . ' — ' . $this->gettext(['name' => 'resultwouldmove', 'vars' => ['folder' => rcmail_action::localize_foldername(trim($fm[1]))]]);
                } elseif ($actions === '(none)' && stripos($keep, 'store message in folder:') !== false) {
                    $counts['kept']++;
                } else {
                    $counts['other']++;
                    $changes[] = $subject . ' — ' . ($actions ?: $keep);
                }
            }
        }

        $total = array_sum($counts);

        if ($total === 0) {
            return ['lines' => [$this->gettext('resultempty')], 'ok' => true];
        }

        $summary_label = $execute ? 'resultsummaryapply' : 'resultsummarypreview';
        $lines = [$this->gettext(['name' => $summary_label, 'vars' => [
            'total' => $total, 'moved' => $counts['moved'], 'discarded' => $counts['discarded'],
        ]])];

        // Cap the visible list -- a folder where almost everything moved
        // (e.g. a first-ever run of a broad rule) could still be huge.
        $shown = array_slice($changes, 0, 200);
        $lines = array_merge($lines, $shown);
        if (count($changes) > count($shown)) {
            $lines[] = $this->gettext(['name' => 'resultmore', 'vars' => ['count' => count($changes) - count($shown)]]);
        }

        return ['lines' => $lines, 'ok' => true];
    }

    /**
     * Shells out to the configured privileged helper (rc-sieve-refilter --
     * see helper/README.md in this repo). The helper -- not this plugin --
     * resolves the active Sieve script for the given username; only a
     * username (from the session, never client input), a folder name, and
     * the discard-policy choice cross the trust boundary, all escaped.
     */
    private function invoke_helper(string $folder, bool $execute, string $discard_policy): array
    {
        $helper = $this->rcube->config->get('sievereplay_helper');

        if (empty($helper)) {
            return ['exit' => -1, 'output' => $this->gettext('errornohelper')];
        }

        // Roundcube's folder list (and thus $folder here) is in IMAP's wire
        // format, modified UTF-7 -- but sieve-filter's <source-mailbox>
        // argument wants plain UTF-8, confirmed by hand: passing a UTF7-IMAP
        // name straight through fails with "Mailbox doesn't exist" even
        // though the mailbox is right there, because the literal '&...-'
        // escape sequences aren't valid UTF-8 mailbox-name characters.
        $folder_utf8 = rcube_charset::convert($folder, 'UTF7-IMAP', 'UTF-8');

        $cmd = $helper
            . ' ' . escapeshellarg($this->rcube->get_user_name())
            . ' ' . escapeshellarg($folder_utf8)
            . ' ' . ($execute ? 'execute' : 'simulate')
            . ' ' . escapeshellarg($discard_policy);

        exec($cmd . ' 2>&1', $output, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }
}
