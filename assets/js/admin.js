(function () {
  'use strict';
  var input = document.getElementById('pairing_code');
  if (!input) return;
  input.addEventListener('input', function () {
    input.value = String(input.value || '')
      .toUpperCase()
      .replace(/[^A-Z0-9-]/g, '');
  });
})();
