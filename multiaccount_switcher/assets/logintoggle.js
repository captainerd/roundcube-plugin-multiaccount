 
 document.addEventListener('DOMContentLoaded', function() {
     var pwdInput = document.getElementById('rcmloginpwd');
     if (!pwdInput) return;

     var tr = pwdInput.closest('tr');
     if (!tr) return;
    
     var toggleRow = document.createElement('tr');
     toggleRow.innerHTML = `
     <td colspan="2">
     <div class="custom-control custom-switch">
     <input type="checkbox" class="custom-control-input" id="rememberme" name="rememberme" checked>
     <label class="custom-control-label" for="rememberme">Remember me</label>
     </div>
     </td>`;

     tr.parentNode.insertBefore(toggleRow, tr.nextSibling);



     (function () {
         const urlParams = new URLSearchParams(window.location.search);
         if (urlParams.get('_task') === 'logout') {
        
             // Delete the cookie by setting it expired
             document.cookie = "multiaccount_session=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT";
             console.log("multiaccount_session cookie deleted due to logout");
         }
     })();


 });
