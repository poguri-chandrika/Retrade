                <footer class="bg-dark text-light py-4 mt-5 rounded-top">
                    <div class="container">
                        <div class="row">
                            <div class="col-md-6">
                                <h5><i class="fas fa-store me-2"></i>Retrade</h5>
                                <p class="mb-0">Your trusted marketplace for buying and selling products safely.</p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <p class="mb-0">&copy; <?php echo date('Y'); ?> Retrade. All rights reserved.</p>
                </footer>
            </main>
        </div>
    
    <!-- Global Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="profileModalLabel">Profile</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="profileContent"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Socket.IO Client -->
    <script src="https://cdn.socket.io/4.7.2/socket.io.min.js"></script>
    <script>
      if (typeof window.io === 'undefined') {
        (function(){
          var s = document.createElement('script');
          s.src = '<?php echo WS_SERVER_URL; ?>/socket.io/socket.io.js';
          s.async = false;
          document.head.appendChild(s);
        })();
      }
    </script>
    <!-- Custom JS -->
    <script src="assets/js/script.js"></script>
    <script src="assets/js/admin.js"></script>
    <script src="assets/js/chat.js"></script>
    <script src="assets/js/order-actions.js"></script>
</body>
</html>
