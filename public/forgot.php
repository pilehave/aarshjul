<?php
declare(strict_types=1);
const PUBLIC_PAGE = true;
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $email = (string)($_POST['email'] ?? '');
    if (filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
        PasswordReset::request($email);
    }
    // Samme svar, uanset om mailen findes
    flash('Hvis adressen hører til en bruger, er der sendt en mail med et link til at vælge en ny adgangskode. Linket virker i '
        . PasswordReset::RESET_MINUTES . ' minutter.');
    redirect('login.php');
}

page_header('Glemt adgangskode');
?>
<form method="post" class="event-form login-form">
  <?= csrf_field() ?>
  <h1>Glemt adgangskode</h1>
  <fieldset>
    <p class="muted">Skriv din mailadresse, så sender vi et link, hvor du kan vælge en ny adgangskode.</p>
    <label>Mail<input type="email" name="email" required autocomplete="username" autofocus></label>
    <div class="actions">
      <button class="btn primary" type="submit">Send link</button>
      <a class="btn" href="login.php">Tilbage</a>
    </div>
  </fieldset>
</form>
<?php page_footer();
