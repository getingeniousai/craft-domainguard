/* DomainGuard Domain Watch — scan runner for the CP section.
 *
 * Starts a scan via the plugin's run-scan action, then polls the
 * scan-status action until the gateway reports done/failed, then
 * reloads so the page renders from the freshly stored report.
 */
(function () {
    var form = document.getElementById('dg-run-scan');
    if (!form || typeof Craft === 'undefined' || !Craft.postActionRequest) {
        return;
    }
    var status = document.getElementById('dg-scan-status');
    var button = form.querySelector('button[type="submit"]');

    function say(text) {
        if (status) {
            status.textContent = text;
        }
    }

    function poll(jobId, tries) {
        if (tries > 40) {
            say('Still running on DomainGuard\'s side — reload this page in a minute to see the result.');
            if (button) { button.disabled = false; }
            return;
        }
        Craft.postActionRequest('domainguard/default/scan-status', { jobId: jobId }, function (response, textStatus) {
            if (textStatus !== 'success' || !response || response.success === false) {
                say((response && response.error) ? response.error : 'Could not check the scan — try again.');
                if (button) { button.disabled = false; }
                return;
            }
            if (response.status === 'done') {
                say('Scan complete — loading your report…');
                window.location.reload();
                return;
            }
            if (response.status === 'failed') {
                say(response.error || 'The scan did not complete — try again in a few minutes.');
                if (button) { button.disabled = false; }
                return;
            }
            say('Scanning ' + (form.getAttribute('data-domain') || 'your domain') + ' — checking lookalikes, certificates and CAA…');
            window.setTimeout(function () { poll(jobId, tries + 1); }, 6000);
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (button) { button.disabled = true; }
        say('Starting scan…');
        Craft.postActionRequest('domainguard/default/run-scan', {}, function (response, textStatus) {
            if (textStatus !== 'success' || !response || response.success === false || !response.jobId) {
                say((response && response.error) ? response.error : 'Could not start the scan — try again.');
                if (button) { button.disabled = false; }
                return;
            }
            poll(response.jobId, 0);
        });
    });
})();
