<?php $pageTitle = SITE_NAME . ' — About'; ?>
<?php require VIEWS_DIR . '/layout/header.php'; ?>

<section class="about-page">

    <div class="about-classification">
        &#9632; CLEARANCE LEVEL: PUBLIC DISCLOSURE &#9632;
    </div>

    <div class="about-logo-wrap">
        <img src="/logo.png" alt="<?= htmlspecialchars(SITE_NAME) ?>" class="about-logo">
    </div>

    <h1>FUTURERADIO</h1>
    <p class="about-subtitle">Authorised Broadcast Infrastructure — Division 7</p>

    <div class="about-body">

        <p>FUTURERADIO is a classified, invite-only audio broadcast platform operating at the intersection of surveillance infrastructure and algorithmic cultural control. Leveraging next-generation internet radio protocols and continuously evolving artificial intelligence systems, FUTURERADIO delivers precisely optimised sonic programming to pre-approved recipients across monitored network segments.</p>

        <div class="about-image-wrap">
            <img src="/stockimageone.png" alt="Authorised Personnel Only" class="about-image">
        </div>

        <p>Access to the FUTURERADIO network is restricted exclusively to vetted operatives and high-clearance personnel. Membership is non-transferable, non-negotiable, and subject to continuous behavioural review by automated compliance systems. Unauthorised access attempts are logged, retained, and forwarded to the appropriate oversight bodies without notice.</p>

        <p>Citizens granted access should consider themselves among the <em>select few</em> deemed fit to receive curated transmissions. This distinction carries both privilege and obligation. All listening activity is recorded for quality assurance, pattern analysis, and national security purposes.</p>

        <div class="about-image-wrap">
            <img src="/stockimagetwo.png" alt="Infrastructure — Restricted Zone" class="about-image">
        </div>

        <p>FUTURERADIO is a joint initiative of the <strong>Defense Advanced Research Projects Agency (DARPA)</strong> and <strong>Palantir Technologies</strong>, operating under Protocol 7 of the Information Dominance Framework. All broadcast content, metadata, and listener telemetry remain the exclusive property of the issuing authorities.</p>

        <div class="about-notice">
            <p>&#9632; This facility is monitored at all times. By proceeding, you acknowledge awareness of and consent to all applicable data retention, signal analysis, and behavioural profiling measures currently in effect. &#9632;</p>
        </div>

    </div>

    <div class="about-actions">
        <a href="/login" class="btn">Request Access</a>
    </div>

    <div class="about-classification about-classification--footer">
        &#9632; FUTURERADIO — A DARPA / PALANTIR INITIATIVE — ALL SIGNALS MONITORED &#9632;
    </div>

</section>

<?php require VIEWS_DIR . '/layout/footer.php'; ?>
