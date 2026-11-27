  </div>
</main>
<footer class="site-footer">
  <div class="container footer-inner">
    <div>
      <div class="footer-brand"><?= e(site_name()) ?></div>
      <div class="footer-tag">Train with purpose. Play at the next level.</div>
    </div>
    <div class="footer-links">
      <?php if (setting('contact_email')): ?>
        <a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a>
      <?php endif; ?>
      <?php if (setting('main_site_url')): ?>
        <a href="<?= e(setting('main_site_url')) ?>">Main website</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="container footer-copy">&copy; <?= date('Y') ?> <?= e(site_name()) ?>. All rights reserved.</div>
</footer>
<script src="<?= e(url('assets/js/app.js')) ?>?v=<?= APP_VERSION ?>"></script>
</body>
</html>
