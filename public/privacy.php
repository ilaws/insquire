<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$title = 'Privacy Policy';
$seoDescription = 'Read how InsQuire collects, uses, stores, and protects account, classroom, and website information.';
$seoSocialTitle = 'InsQuire Privacy Policy';
$seoCanonicalUrl = (isEducationHost() ? EDUCATION_APP_URL : PERSONAL_APP_URL) . '/privacy.php';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<article class="card policy-page">
    <h1>Privacy Policy</h1>
    <p><strong>Last updated: October 24, 2026</strong></p>

    <h2>1. Information we collect</h2>
    <p>We collect information you provide when you create or use an account, including your name, email address, account role, password hash, classes, assignments, lesson activity, submissions, and feedback. Teachers may upload rubric text files.</p>
    <p>The Service also receives technical information needed to operate securely, such as IP address, browser or device information, session identifiers, and security logs. Essential cookies support authentication, accessibility preferences, and security protections.</p>

    <h2>2. How we use information</h2>
    <p>We use information to authenticate accounts, provide classroom and assignment features, save progress, display feedback, protect the Service, troubleshoot failures, and improve reliability. If you consent, Google Analytics collects page-visit information across public and signed-in pages, including school workspaces, to help us understand and improve the site. Analytics is not loaded unless you accept it. Page paths are sent without query strings.</p>
    <p>AI feedback is generated to support learning and is not a substitute for a teacher's judgment.</p>

    <h2>3. How information is shared</h2>
    <p>Student work and progress are shared with the teacher and class members who are authorized to access the relevant class or assignment. We may use infrastructure and service providers to host, secure, and operate the Service. Google Analytics may process website usage information after you consent. We may disclose information when required by law or when necessary to protect users, the Service, or our rights. We do not sell student work or personal information.</p>

    <h2>4. Retention and deletion</h2>
    <p>We retain account and classroom information while an account or classroom relationship is active, and longer when needed for security, legal, or backup purposes. You may request correction or deletion of your account information by contacting us. Deleting information may remove access to related submissions, lesson progress, or class records.</p>

    <h2>5. Children and schools</h2>
    <p>InsQuire may be used in educational settings. Schools, teachers, and parents or guardians are responsible for ensuring that use complies with applicable student privacy laws and their policies. We do not knowingly collect more information from children than is needed to provide the Service.</p>

    <h2>6. Security</h2>
    <p>We use password hashing, HTTPS, server-side authorization, CSRF protection, access-controlled sessions, and server-side storage for database and AI credentials. No online service can guarantee absolute security. Never share your password or upload confidential material that your school does not permit you to use.</p>

    <h2>7. Your choices and questions</h2>
    <p>You may update profile information through the Service and change analytics consent using <a href="#cookie-settings" id="privacy-cookie-settings">Cookie settings</a>. For school-managed accounts, direct requests about student records to the relevant school administrator.</p>

    <h2>8. Changes</h2>
    <p>We may update this policy as the Service changes. The date above identifies the current version.</p>
</article>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
