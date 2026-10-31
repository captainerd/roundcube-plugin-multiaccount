document.addEventListener('DOMContentLoaded', function() {
  if (!window.AccountSwitcherConfig) {
    return;
  }
  rcmail.refresh_list();
  const t = (k) => rcmail.gettext('multiaccount_switcher.' + k);
  const { csrfToken, currentEmail, switchUrl, deleteUrl, rootUrl } = window.AccountSwitcherConfig;

  // Handle add account form submit
  const addForm = document.getElementById('addAccountForm');
  if (addForm) {
    addForm.addEventListener('submit', function(e) {
      e.preventDefault();

      const username = document.getElementById('addAccountUsername').value.trim();
      const password = document.getElementById('addAccountPassword').value;

      if (!username || !password) {
        rcmail.display_message(t('err_missing_credentials'), 'danger');
        return;
      }

      const url = rootUrl + '&_action=plugin.multiaccount_switcher.validate_account';
      const data = new URLSearchParams({
        _token: csrfToken,
        username: username,
        password: password,
      });

      fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: data.toString()
      })
      .then(response => response.json())
      .then(data => {
        if (data.status === 'success') {
          rcmail.display_message(t('msg_added'), 'success');
          addForm.reset();
          $('#addAccountModal').modal('hide');
          setTimeout(() => location.reload(), 4000);
        } else {
          rcmail.display_message(data.message || t('err_validation'), 'error');
        }
      })
      .catch(() => {
        rcmail.display_message(t('err_request'), 'error');
      });
    });
  }

  function switchTo(email) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = switchUrl;

    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'account';
    input.value = email;
    form.appendChild(input);

    const inputCsrf = document.createElement('input');
    inputCsrf.type = 'hidden';
    inputCsrf.name = '_token';
    inputCsrf.value = csrfToken;
    form.appendChild(inputCsrf);

    document.body.appendChild(form);
    form.submit();
  }

  // Main menu item opens the accounts dialog
  const menuBtn = document.getElementById('multiaccount-menu');
  if (menuBtn) {
    menuBtn.addEventListener('click', function(e) {
      e.preventDefault();
      $('#manageAccountsModal').modal('show');
    });
  }

  // Click on an account in the dialog switches to it
  document.querySelectorAll('.switch-account-link').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      switchTo(this.dataset.email);
    });
  });

  // Dropdown change handler for switching or managing accounts
  const select = document.querySelector('.custom-select.pretty-select');
  if (select) {
    select.addEventListener('change', function() {
      if (this.value === 'manage') {
        $('#manageAccountsModal').modal('show');
        this.value = currentEmail;
      } else {
        switchTo(this.value);
 
        
      }

    });
  }

  // Delete account buttons
  document.querySelectorAll('.delete-account-btn').forEach(btn => {
    btn.addEventListener('click', function () {
      const email = this.dataset.email;
      if (!confirm(t('confirm_delete').replace('$email', email))) return;

      const data = new URLSearchParams({
        _token: csrfToken,
        email: email
      });

      fetch(deleteUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: data.toString()
      })
      .then(response => response.json())
      .then(data => {
        if (data.status === 'success') {
          rcmail.display_message(data.message, 'success');
          const row = this.closest('[data-email$="-row"]');
          if (row) row.remove();
          setTimeout(() => location.reload(), 4000);
        } else {
          rcmail.display_message(data.message, 'danger');
        }
             
      })
      .catch(() => {
        rcmail.display_message(t('err_request'), 'danger');
      });
    });
  });

  // Add Account button to show Add Account modal
  const addBtn = document.getElementById('addAccountBtn');
  if (addBtn) {
    addBtn.addEventListener('click', function() {
      $('#manageAccountsModal').modal('hide');
      $('#addAccountModal').modal('show');
    });
  }
});
