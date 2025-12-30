<?php
// /public/modules/get/main.php
// Module-level router for ChaosCMS Files
/**
 * This router isolates URL segments that come *after* the module?s
 * directory name. It then interprets the first segment as either ?home?
 * or a specific action, and optionally a second segment as an argument.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$partsAll = array_values(array_filter(explode('/', trim($path, '/'))));

// Find this module's directory name in the path and strip everything up to it.
// This prevents the router from hijacking other top-level paths.
$moduleDir = basename(__DIR__);
$moduleIndex = array_search($moduleDir, $partsAll);
$parts = ($moduleIndex !== false)
    ? array_slice($partsAll, $moduleIndex + 1)
    : $partsAll;

// Determine action and optional argument.
// Pattern A: /home/<action>/<arg>
// Pattern B: /<action>/<arg>
$action = 'home';
$arg    = null;

if (!empty($parts)) {
    if (strtolower($parts[0]) === 'home') {
        $action = strtolower($parts[1] ?? 'home');
        $arg    = strtolower($parts[2] ?? null);
    } else {
        $action = strtolower($parts[0]);
        $arg    = strtolower($parts[1] ?? null);
    }
}

/** home()
 * Entry point to this module
 */
function home(): void {
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handle_contact_submission();
    } else {
        render_contact_form();
    }
}

/**
 * Renders the HTML contact form.
 */
function render_contact_form(): void
{
    ?>
    <div class="container">
      <div class="row">
        <h2>Contact Us</h2>
        <form method="post" action="">
          <label for="name">Name<span style="color:red">*</span></label><br>
          <input type="text" id="name" name="name" required><br><br>
        
          <label for="email">Email<span style="color:red">*</span></label><br>
          <input type="email" id="email" name="email" required><br><br>
        
          <label for="subject">Subject</label><br>
          <input type="text" id="subject" name="subject" placeholder="Contact Form Submission"><br><br>
        
          <label for="topic">Please choose:</label>

		  <select name="topic" id="topic">
		    <option value="join">Join Us</option>
  			<option value="feedback">Feedback</option>
  			<option value="issue">Issues</option>
  			<option value="other">Other</option>
		  </select>
		  <br>
		  <br>
        
          <label for="message">Message<span style="color:red">*</span></label><br>
          <textarea id="message" name="message" rows="6" cols="50" required></textarea><br><br>
        
          <button type="submit">Send Message</button>
        </form>
      </div>
    </div>
    <?php
}

/**
 * Handles the contact form submission: validates, stores, and emails the message.
 */
function handle_contact_submission(): void {
	$name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? 'Contact Form Submission');
    $topic = trim($_POST['topic'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Basic validation
    if ($name === '' || $email === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo '<p style="color:red">Please fill out all required fields with valid information.</p>';
        render_contact_form();
        return;
    }

    // Prepare data for storage
    $entry = [
        'name'    => strip_tags($name),
        'email'   => strip_tags($email),
        'subject' => strip_tags($subject),
        'topic' => strip_tags($topic),
        'message' => strip_tags($message),
        'date'    => date('c'),
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ];

    // Save the message to a JSON file
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }
    $dataFile = $dataDir . '/contact_messages.json';
    $messages = [];
    if (file_exists($dataFile)) {
        $json = file_get_contents($dataFile);
        $messages = json_decode($json, true);
        if (!is_array($messages)) {
            $messages = [];
        }
    }
    $messages[] = $entry;
    file_put_contents($dataFile, json_encode($messages, JSON_PRETTY_PRINT));

    // Optionally send email to site administrator using the CMS mailer class.
    // Adjust $adminEmail to your administrator email address.
    $adminEmail = 'devteam@chaoscms.org';
    $mailSubject = 'Chaos CMS Contact: ' . $entry['subject'];
    $mailBody = "Name: {$entry['name']}\n";
    $mailBody .= "Email: {$entry['email']}\n";
    $mailBody .= "Date: {$entry['date']}\n";
    $mailBody .= "Topic: {$entry['topic']}\n";
    $mailBody .= "Message:\n{$entry['message']}\n";

    // Use the CMS mailer class configured via a JSON file (mailer.json).
    // This allows you to set a custom "From" address and other SMTP settings
    // outside of the code.  The JSON should be placed in the module's data
    // directory (e.g., modules/contact/data/mailer.json) and follow the same
    // structure as other modules.
    // Determine where the mailer configuration lives.  In Chaos CMS Lite, the
    // global config is typically located in the root data directory
    // (/public/data/mailer.json).  Look there first, then fall back to a
    // module-specific config (modules/contact/data/mailer.json) if present.
    $mailerJsonPath = __DIR__ . '/../../data/mailer.json';
    if (!is_file($mailerJsonPath)) {
        $mailerJsonPath = __DIR__ . '/data/mailer.json';
    }
    $mailSent = false;
    if (is_file($mailerJsonPath)) {
        $cfgRaw = file_get_contents($mailerJsonPath);
        $cfg = json_decode($cfgRaw, true);
        if (is_array($cfg)) {
            // Allow environment overrides for sensitive fields like password
            if (getenv('MAIL_PASS')) {
                $cfg['pass'] = getenv('MAIL_PASS');
            }
            // Coerce some integer fields to the proper type
            $cfg['port']             = isset($cfg['port']) ? (int)$cfg['port'] : 465;
            $cfg['timeout']          = isset($cfg['timeout']) ? (int)$cfg['timeout'] : 180;
            $cfg['connectRetries']   = isset($cfg['connectRetries']) ? (int)$cfg['connectRetries'] : 3;
            $cfg['connectBackoffMs'] = isset($cfg['connectBackoffMs']) ? (int)$cfg['connectBackoffMs'] : 500;
            // Load the mailer class; adjust the path if needed
            $mailerPath = __DIR__ . '/../../app/core/mailer.php';
            if (file_exists($mailerPath)) {
                require_once $mailerPath;
                try {
                    // Create a new instance of the namespaced mailer
                    $mailer = new app\core\mailer($cfg);
                    $mailSent = $mailer->send($adminEmail, $mailSubject, $mailBody);
                } catch (Throwable $e) {
                    $mailSent = false;
                }
            }
        }
    }
    if (!$mailSent) {
        // Fallback to PHP mail() if sending through the mailer failed or
        // configuration is missing.  Build a proper From header.  The
        // mailer.json file may specify the "from" field as either a string
        // (email address) or an array [email, name].  If absent, fall
        // back to the administrator email.  Prevent undefined variable
        // warnings by checking $cfg before use.
        $fromEmail = $adminEmail;
        $fromName  = '';
        if (isset($cfg) && is_array($cfg) && isset($cfg['from'])) {
            if (is_array($cfg['from']) && count($cfg['from']) >= 2) {
                $fromEmail = $cfg['from'][0];
                $fromName  = $cfg['from'][1];
            } elseif (is_string($cfg['from'])) {
                $fromEmail = $cfg['from'];
            }
        }
        // Construct the From header. Use "Name <email>" if a name is provided.
        $fromHeader = $fromName !== '' ? "$fromName <$fromEmail>" : $fromEmail;
        $headers = "From: $fromHeader\r\n";
        @mail($adminEmail, $mailSubject, $mailBody, $headers);
    }

    echo '<p>Thank you for your message. We will get back to you soon.</p>';
}

// Route based on the computed action and arg
switch ($action) {
    case 'home':
    case '':
        home();
        break;

    default:
        http_response_code(404);
        echo '<h2>Not found</h2>';
        break;
}
