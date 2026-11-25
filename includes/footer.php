<footer class="footer">
	<p>&copy; <?= date('Y') ?> InsQuire</p>
	<p><a href="/privacy.php">Privacy Policy</a> <span aria-hidden="true">&middot;</span> <a href="/tos.txt">Terms of Use</a></p>
	<?php if ($analyticsPageAllowed): ?><p><a href="#cookie-settings" id="cookie-settings-link">Cookie settings</a></p><?php endif; ?>
</footer>
</main>
<?php if ($analyticsPageAllowed): ?>
<section class="cookie-consent" id="cookie-consent" aria-label="Cookie preferences" hidden>
	<p>InsQuire uses essential cookies to run the service. With your permission, Google Analytics helps us understand site usage. <a href="/privacy.php">Privacy Policy</a>.</p>
	<div class="cookie-consent-actions">
		<button class="button secondary" type="button" data-cookie-choice="reject">Reject analytics</button>
		<button class="button" type="button" data-cookie-choice="accept">Accept analytics</button>
	</div>
</section>
<script src="/assets/consent.js?v=3" defer></script>
<?php endif; ?>
</body>
</html>
