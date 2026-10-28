

<?php  if (isset($_SESSION['message'])): ?>
  <div class="popup">
    <?php echo $_SESSION['message'] ;?>
  </div>
  <?php unset($_SESSION['message']); ?>

<?php  elseif (isset($_SESSION['error'])): ?>
  <div class="popup error">
    <?php echo $_SESSION['error'] ;?>
  </div>
  <?php unset($_SESSION['error']); ?>

<?php endif; ?>