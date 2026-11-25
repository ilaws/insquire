<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$isEducationSite = isEducationHost();
$pageBodyClass = $isEducationSite ? 'education-landing' : 'personal-landing';
$title = $isEducationSite ? 'Education' : 'Assignments and Writing';
$seoDescription = $isEducationSite
    ? 'Organize classes, assignments, lessons, and student writing in one focused workspace for schools.'
    : 'Track assignments and due dates, plan your writing, and get feedback on your drafts with InsQuire.';
$seoSocialTitle = $isEducationSite
    ? 'InsQuire for Education | Classroom writing support'
    : 'InsQuire | Assignments and Writing';
$seoCanonicalUrl = $isEducationSite ? EDUCATION_APP_URL . '/' : PERSONAL_APP_URL . '/';

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="<?= $isEducationSite ? '' : 'personal-home' ?>">
<?php if ($isEducationSite): ?>
<section class="app-welcome">
    <div>
        <p class="app-kicker">INSQUIRE FOR EDUCATION</p>
        <h1>Classes and coursework</h1>
        <p>Students access assignments, lessons, and returned feedback. Teachers manage classes and review submitted work.</p>
    </div>
    <div class="app-welcome-actions">
        <a class="button" href="register.php">Sign up</a>
        <a class="app-text-link" href="login.php">Log in</a>
        <p>Student accounts join InsQuire Test automatically. Teachers need a school invitation.</p>
    </div>
</section>
<nav class="workspace-tabs" aria-label="Dashboard previews">
    <a aria-current="location">Student view</a>
</nav>
<section class="workspace-demo" aria-labelledby="student-view">
    <div class="workspace-demo-heading">
        <div><p class="app-kicker">STUDENT DASHBOARD</p><h2 id="student-view">Classes and coursework</h2></div>
    </div>
    <div class="education-preview-grid">
        <article class="education-preview-card">
            <div class="course-band course-band-blue">Classes</div>
            <h3>Your classes</h3>
            <p>Open a class to find its assignments, lessons, and shared resources.</p>
            <div class="preview-detail"><strong>Class access</strong><span>Join with a code from your school</span></div>
        </article>
        <article class="education-preview-card">
            <div class="course-band course-band-green">Assignments</div>
            <h3>What’s due</h3>
            <p>See published assignments, due dates, and work returned for revision.</p>
            <div class="preview-detail"><strong>Writing</strong><span>Draft, submit, and review teacher feedback</span></div>
        </article>
        <article class="education-preview-card">
            <div class="course-band course-band-gold">Lessons</div>
            <h3>Continue learning</h3>
            <p>Open lessons assigned by your teacher and keep your classwork together.</p>
            <div class="preview-detail"><strong>Progress</strong><span>Pick up where you left off</span></div>
        </article>
    </div>
</section>
<section class="workspace-demo teacher-demo" aria-labelledby="teacher-view">
    <div class="workspace-demo-heading">
        <div><p class="app-kicker">TEACHER DASHBOARD</p><h2 id="teacher-view">Classroom tools</h2></div>
    </div>
    <div class="teacher-tool-list">
        <article><span class="tool-icon" aria-hidden="true">01</span><div><h3>Classes and rosters</h3><p>Create classes, invite staff, and share join codes with students.</p></div></article>
        <article><span class="tool-icon" aria-hidden="true">02</span><div><h3>Assignments and review</h3><p>Publish work, review student submissions, and return feedback.</p></div></article>
        <article><span class="tool-icon" aria-hidden="true">03</span><div><h3>Lessons</h3><p>Build lessons and assign them to your classes.</p></div></article>
    </div>
</section>
<?php else: ?>
<section class="personal-welcome" aria-labelledby="personal-welcome-title">
    <div class="welcome-copy">
        <p class="app-kicker">PERSONAL WORKSPACE</p>
        <h1 id="personal-welcome-title">One assignment, one place to work through it.</h1>
        <p>Keep each prompt, due date, draft notes, and its own saved AI writing-coach chat together.</p>
    </div>
    <div class="personal-actions">
        <a class="button" href="register.php">Create a Personal account</a>
        <a class="app-text-link" href="login.php">Log in</a>
    </div>
</section>
<section class="workspace-demo personal-demo" aria-labelledby="personal-preview-title">
    <div class="workspace-demo-heading">
        <div><p class="app-kicker">DASHBOARD PREVIEW</p><h2 id="personal-preview-title">Assignments and their own coach chats</h2></div>
    </div>
    <div class="personal-preview-grid">
        <section class="personal-assignment-preview" aria-label="Sample assignments with individual coach chats">
            <article class="assignment-preview-card">
                <div class="assignment-preview-meta"><span>WRITING ASSIGNMENT</span><span>Due Oct 24</span></div>
                <h3>Research response</h3>
                <p>Use sources to explain how later school start times affect students.</p>
                <div class="assignment-preview-chat">
                    <span>CHAT · RESEARCH RESPONSE</span>
                    <p><strong>You:</strong> My sources focus on attention in morning classes. How should I use that?</p>
                    <p><strong>Coach:</strong> Keep attention as the focus. Connect what your sources show to your claim in the body paragraph.</p>
                </div>
            </article>
            <article class="assignment-preview-card">
                <div class="assignment-preview-meta"><span>REVISION</span><span>In progress</span></div>
                <h3>Personal narrative draft</h3>
                <p>Revise the opening and check that each paragraph moves the story forward.</p>
                <div class="assignment-preview-chat">
                    <span>CHAT · PERSONAL NARRATIVE</span>
                    <p><strong>You:</strong> Does this opening give enough context?</p>
                    <p><strong>Coach:</strong> It establishes the setting. What does the reader need to know about the moment before the action starts?</p>
                </div>
            </article>
        </section>
    </div>
</section>
<nav class="workspace-tabs" aria-label="Personal workspace information">
    <a aria-current="location">Overview</a>
</nav>
<section id="overview" class="personal-overview" aria-labelledby="overview-title">
    <div class="personal-section-heading">
        <h2 id="overview-title">What you can do</h2>
    </div>
    <div class="personal-cards">
        <article class="personal-card">
            <h3>Organize each assignment</h3>
            <p>Keep an assignment’s instructions, notes, and due date together. Open it to continue its own writing-coach chat.</p>
        </article>
        <article class="personal-card">
            <h3>Get writing suggestions</h3>
            <p>Share an excerpt or describe a writing question to receive focused suggestions.</p>
        </article>
        <article class="personal-card">
            <h3>Use your calendar</h3>
            <p>Export your planned work and due dates to a calendar app.</p>
        </article>
    </div>
</section>
<section id="how-it-works" class="personal-how-it-works" aria-labelledby="how-title">
    <div>    <p class="app-kicker">WRITING SUPPORT</p><h2 id="how-title">Suggestions, not automatic edits.</h2></div>
    <p>Review suggestions and decide what to change. InsQuire does not revise or submit your work for you.</p>
</section>
<?php endif; ?>
</div>

<?php if (!($loggedIn ?? false)): ?>
<div class="mobile-sticky-cta">
    <a class="button" href="register.php"><?= $isEducationSite ? 'Sign up' : 'Create a Personal account' ?></a>
</div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
