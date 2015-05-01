/*
    JavaScript for Global user contributions

    Author: Luxo, 2013
    Author: Krinkle, 2015
 */

function onSearchClick(button) {
    button.hide();
    $('loadLine').show();
    window.setInterval(setLines, 1000);
}

function setLines() {
    $('loadLine').firstChild.nodeValue += '|';
}

window.onload = function () {
    /*global data */

    // Automatically submit the form if the user came here with
    // a permalink and the username is non-empty.
    if (data.Method == 'GET' && data.Username) {
        $('searchForm').submit();
        onSearchClick($('submitButton'));
    }
};

