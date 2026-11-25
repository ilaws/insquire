<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$title = 'Education';
$pageBodyClass = 'education-landing';
$seoDescription = 'InsQuire Education brings classes, assignments, lessons, and writing feedback into one school workspace.';
$seoSocialTitle = 'InsQuire for Education | Classes and coursework';
$seoCanonicalUrl = PERSONAL_APP_URL . '/education.php';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<section class="app-welcome">
    <div>
        <p class="app-kicker">INSQUIRE FOR EDUCATION</p>
        <h1>Classes and coursework</h1>
        <p>Teachers manage classes, assignments, lessons, and submissions. Student signups are automatically added to the InsQuire Test class.</p>
    </div>
    <div class="app-welcome-actions">
        <a class="button" href="<?= e(EDUCATION_APP_URL) ?>">Open Education</a>
        <a class="app-text-link" href="<?= e(EDUCATION_APP_URL . '/register.php') ?>">Sign up</a>
        <p>Teacher accounts require a school invitation.</p>
    </div>
</section>

<section class="workspace-demo" aria-labelledby="education-tools-title">
    <div class="workspace-demo-heading">
        <div>
            <p class="app-kicker">CLASSROOM WORKSPACE</p>
            <h2 id="education-tools-title">Tools for students and teachers</h2>
        </div>
    </div>
    <div class="education-preview-grid">
        <article class="education-preview-card">
            <div class="course-band course-band-blue">STUDENT</div>
            <h3>Find classwork</h3>
            <p>Open your classes, check assignment due dates, continue lessons, and review feedback returned by teachers.</p>
        </article>
        <article class="education-preview-card">
            <div class="course-band course-band-green">TEACHER</div>
            <h3>Manage your classes</h3>
            <p>Create classes, invite staff, share student join codes, and organize assignments and lessons.</p>
        </article>
        <article class="education-preview-card">
            <div class="course-band course-band-gold">WRITING</div>
            <h3>Keep feedback in context</h3>
            <p>Review submitted writing and return feedback within the class where the assignment belongs.</p>
        </article>
    </div>
</section>

<section class="personal-how-it-works">
    <div>
        <p class="app-kicker">SEPARATE ACCOUNT TYPES</p>
        <h2>Education and Personal accounts are separate.</h2>
    </div>
    <a class="app-text-link" href="<?= e(PERSONAL_APP_URL . '/register.php') ?>">Create a Personal account</a>
</section>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
