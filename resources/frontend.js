/* 
 * by Luxo 2013
 */


var timeCounter = 0;
function onSearchClick(button) {
    button.hide();    
    $('loadLine').show();    
    window.setInterval("setLines()",1000);
}

function setLines() {
    var content = "|";    
    for(var i=0;i<timeCounter;i++){
        content+= "|";
    }
    $('loadLine').firstChild.data = content;
    timeCounter++;
}

window.onload = function() {
    //Formular automatisch absenden
    if(data.Method == 'GET' && data.Username) {
        $('searchForm').submit();
        onSearchClick($('submitButton'));
    }
};

