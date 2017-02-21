/*
    JavaScript for Global user contributions

    Author: Luxo, 2013
    Author: Krinkle, 2015
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
        }
    };

    getId('submitButton').addEventListener('click', function () {
        onSearchClick(this);
    }, false);

}());
