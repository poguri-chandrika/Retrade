// Admin layout interactions: sidebar toggle for mobile
(function(){
  const sidebar = document.getElementById('appSidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const toggleBtn = document.getElementById('sidebarToggle');

  function open(){
    if (sidebar) sidebar.classList.add('open');
    if (overlay) overlay.classList.add('show');
  }
  function close(){
    if (sidebar) sidebar.classList.remove('open');
    if (overlay) overlay.classList.remove('show');
  }
  function toggle(){
    if (!sidebar) return;
    if (sidebar.classList.contains('open')) close();
    else open();
  }

  if (toggleBtn) toggleBtn.addEventListener('click', toggle);
  if (overlay) overlay.addEventListener('click', close);

  // Close sidebar on desktop resize
  window.addEventListener('resize', function(){
    if (window.innerWidth >= 992) close();
  });
})();
