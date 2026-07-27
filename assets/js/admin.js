(function () {
  'use strict';

  var container = document.querySelector('[data-mrnp-platforms]');
  var template = document.querySelector('[data-mrnp-platform-template]');
  var add = document.querySelector('[data-mrnp-add-platform]');

  function bindRemove(scope) {
    scope.querySelectorAll('[data-mrnp-remove-platform]').forEach(function (button) {
      button.addEventListener('click', function () {
        var row = button.closest('.mrnp-platform-row');
        if (row) {
          row.remove();
        }
      });
    });
  }

  if (container && template && add) {
    bindRemove(container);
    add.addEventListener('click', function () {
      var index = container.querySelectorAll('.mrnp-platform-row').length + Date.now();
      var holder = document.createElement('div');
      holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(index));
      var row = holder.firstElementChild;
      container.appendChild(row);
      bindRemove(row);
      row.querySelector('input').focus();
    });
  }

  document.querySelectorAll('[data-mrnp-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      navigator.clipboard.writeText(button.dataset.mrnpCopy).then(function () {
        var original = button.textContent;
        button.textContent = 'کپی شد';
        window.setTimeout(function () {
          button.textContent = original;
        }, 1400);
      });
    });
  });
}());
