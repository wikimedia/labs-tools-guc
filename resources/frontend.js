/*
    JavaScript for Global user contributions

    Author: Luxo, 2013
    Author: Krinkle, 2015
 */
(function () {
    if (!(
        document.getElementById &&
        'addEventListener' in window
    )) {
        // Unsupported browser
        return;
    }

    function getId (id) {
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
    }

    function setLines(loader) {
        loader.firstChild.nodeValue += '|';
    }

    window.onload = function () {
        /*global data */

        // Automatically submit the form if the user came here with
        // a permalink and the username is non-empty.
        if (data.Method == 'GET' && data.Username) {
            getId('searchForm').submit();
            onSearchClick(getId('submitButton'));
        } else if (data.Method == 'POST') {
            setLocation(data);
        }
    };

    getId('submitButton').addEventListener('click', function () {
        onSearchClick(this);
    }, false);

}());
