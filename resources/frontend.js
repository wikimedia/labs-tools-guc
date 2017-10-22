/**
 * JavaScript for Global user contributions
 *
 * @author Luxo
 * @author Krinkle
 * @copyright 2013-2015
 * @file
 */
(function () {
    if (!(
        document.getElementById &&
        'addEventListener' in window &&
        !/bot|spider/i.test( navigator.userAgent )
    )) {
        // Unsupported browser
        return;
    }

    function getId(id) {
        return document.getElementById(id);
    }

    function setLocation(data) {
        if (history.replaceState) {
            history.replaceState(null, document.title, data.Permalink);
        }
    }

    function checkReplag() {
        if (typeof fetch === 'undefined') {
            return;
        }
        fetch(
            'https://tools.wmflabs.org/guc/api.php?q=replag',
            // Enable credentials so that any Intuition cookie will be
            // available to the API for the lagged warning message.
            { method: 'GET', credentials: 'same-origin' }
        )
            .then(function (resp) {
                return resp.json();
            })
            .then(function (data) {
                if (data.error) {
                    return Promise.reject(data.error);
                }
                return data.lagged;
            })
            .then(function (lagged) {
                if (!lagged) {
                    return;
                }
                var node = document.createElement('div');
                node.className = 'error';
                node.innerHTML = lagged.html;
                var target = document.querySelector('.maincontent form');
                target.parentNode.insertBefore(node, target.nextSibling);
            })
            .catch(function (err) {
                if (!window.console || !console.error) {
                    return;
                }
                console.warn('Failed to fetch replag information');
                console.error('[Replag API] ' + err);
            });
    }

    function onSearchClick(button) {
        // Remove button
        button.style.display = 'none';
        // Add loader
        var loaderNode = getId('loadLine');
        loaderNode.style.display = '';

        setInterval(function () {
            setLines(loaderNode);
        }, 1000);

        // Unhide the button if the form fields are changed
        // by the user. No need to force them to wait out
        // the current query.
        getId('searchForm').onchange = function () {
            // Once
            this.onchange = null;
            // Undo hidden button
            button.style.display = '';
        };
    }

    function setLines(loader) {
        loader.firstChild.nodeValue += '|';
    }

    window.onload = function () {
        /*global GucData */

        // Automatically submit the form if the user came here with
        // a permalink and the username is non-empty.
        if (GucData.Method == 'GET' && GucData.Username) {
            getId('searchForm').submit();
            onSearchClick(getId('submitButton'));
        } else if (GucData.Method == 'POST') {
            setLocation(GucData);
            checkReplag();
        }
    };

    getId('submitButton').addEventListener('click', function () {
        onSearchClick(this);
    }, false);

}());
