// sievereplay: Settings page wiring for the preview -> apply flow.
//
// Preview always runs first (simulate mode, no changes). Only once a
// preview has completed does Apply become enabled, and Apply always sends
// its own separate confirm dialog before the execute-mode request goes out
// -- this mirrors the explicit two-step confirmation the underlying
// sieve-filter tool itself recommends for anything that can move/discard
// mail.

if (window.rcmail) {
    function sievereplayShowBusy(label) {
        var $box = $('#sievereplay-result').empty().removeClass('sievereplay-error');
        var $row = $('<div class="sievereplay-busy">').appendTo($box);
        $('<div class="spinner-border spinner-border-sm">').appendTo($row);
        $('<span>').text(label).appendTo($row);
        $('#sievereplay-preview, #sievereplay-apply').prop('disabled', true);
    }

    rcmail.addEventListener('plugin.sievereplay_result', function (result) {
        var $box = $('#sievereplay-result').empty().removeClass('sievereplay-error');

        if (!result.ok) {
            $box.addClass('sievereplay-error');
        }

        (result.lines || []).forEach(function (line) {
            $('<div>').text(line).appendTo($box);
        });

        if (result.output) {
            var $details = $('<details>').appendTo($box);
            $('<summary>').text(rcmail.gettext('sievereplay.showdetails')).appendTo($details);
            $('<pre>').text(result.output).appendTo($details);
        }

        $('#sievereplay-preview').prop('disabled', false);
        $('#sievereplay-apply').prop('disabled', !(result.ok && result.exit === 0));
    });

    rcmail.addEventListener('init', function () {
        $('#sievereplay-preview').on('click', function () {
            sievereplayShowBusy(rcmail.gettext('sievereplay.checking'));
            rcmail.http_post('plugin.sievereplay.preview', {
                folder: $('#sievereplay-folder').val(),
                discard: $('#sievereplay-discard').val(),
            });
        });

        $('#sievereplay-apply').on('click', function () {
            if (!window.confirm(rcmail.gettext('sievereplay.confirmapply'))) {
                return;
            }

            sievereplayShowBusy(rcmail.gettext('sievereplay.applying'));
            rcmail.http_post('plugin.sievereplay.run', {
                folder: $('#sievereplay-folder').val(),
                discard: $('#sievereplay-discard').val(),
            });
        });
    });
}
