<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="description" content="StudentLink helps students discover academic resources, connect with study groups,
 share items, and find useful student services.">

    <title>StudentLink — Your Campus, Your Community</title>

    <link rel="stylesheet" href="css/index.css">
</head>
<body>

    <!-- NAVIGATION -->
    <header class="landing-header">
        <nav class="navbar container" aria-label="Main navigation">

            <a href="#home" class="brand">
                <span class="brand-mark">S.</span>
                <span>StudentLink</span>
            </a>

            <div class="nav-links">
                <a href="#home" class="nav-link active">Home</a>
                <a href="#about" class="nav-link">About</a>
                <a href="#features" class="nav-link">Features</a>
                <a href="#how-it-works" class="nav-link">How it works</a>
            </div>

            <div class="nav-actions">
                <a href="login.php" class="nav-login">Log in</a>
                <a href="register.php" class="nav-signup">Get started <span>↗</span></a>
            </div>

            <button
                class="menu-toggle"
                type="button"
                aria-label="Toggle navigation"
                aria-expanded="false"
                aria-controls="mobile-nav"
            >
                <span></span>
                <span></span>
            </button>

        </nav>

        <div class="mobile-nav" id="mobile-nav">
            <a href="#home">Home</a>
            <a href="#about">About</a>
            <a href="#features">Features</a>
            <a href="#how-it-works">How it works</a>
            <a href="login.php">Log in</a>
            <a href="register.php">Create an account ↗</a>
        </div>
    </header>


    <main>

        <!-- HERO SECTION -->
        <section class="hero section" id="home">
            <div class="container hero-grid">

                <div class="hero-content">

                    <div class="eyebrow">
                        <span class="status-dot"></span>
                        A BETTER WAY TO BE A STUDENT
                    </div>

                    <h1>
                        Student life is<br>
                        better <span class="highlight">together.</span>
                    </h1>

                    <p class="hero-description">
                        Have a question of your own? Join other students
                        to unravel the unknown. Discover resources, find
                        your people, and make campus life a little easier.
                    </p>

                    <div class="hero-actions">
                        <a href="register.php" class="btn btn-primary">
                            Join StudentLink <span>↗</span>
                        </a>

                        <a href="#features" class="btn btn-secondary">
                            Explore the platform <span>↓</span>
                        </a>
                    </div>

                    <div class="hero-note">
                        <span class="note-icon">✳</span>
                        <p>
                            <strong>Learn together. Grow together.</strong>
                            <span>A community built around student needs.</span>
                        </p>
                    </div>

                </div>

                <!-- HERO VISUAL -->
                <div class="hero-visual" aria-label="StudentLink platform overview">

                    <div class="visual-top">
                        <span class="visual-label">YOUR STUDENT SPACE</span>
                        <span class="visual-status">
                            <span></span> CONNECTED
                        </span>
                    </div>

                    <div class="visual-heading">
                        <span>ONE PLACE.</span>
                        <h2>More ways<br>to move forward.</h2>
                    </div>

                    <div class="visual-cards">

                        <a href="resources.php" class="visual-card card-orange">
                            <span class="visual-card-icon">↗</span>
                            <span class="visual-card-number">01 / LEARN</span>
                            <strong>Find resources</strong>
                            <small>Notes, guides and study materials</small>
                        </a>

                        <a href="groups.php" class="visual-card card-light">
                            <span class="visual-card-icon">◎</span>
                            <span class="visual-card-number">02 / CONNECT</span>
                            <strong>Meet your group</strong>
                            <small>Study better with other students</small>
                        </a>

                        <a href="items.php" class="visual-card card-dark">
                            <span class="visual-card-icon">↔</span>
                            <span class="visual-card-number">03 / SHARE</span>
                            <strong>Exchange items</strong>
                            <small>Find useful things within your community</small>
                        </a>

                    </div>

                    <div class="visual-footer">
                        <span>MADE FOR STUDENTS</span>
                        <span>SL / 001</span>
                    </div>

                    <div class="visual-decoration" aria-hidden="true">✳</div>

                </div>

            </div>

            <div class="hero-bottom container">
                <span>LESS SEARCHING, MORE DISCOVERING.</span>
                <a href="#about">GET TO KNOW US <span>↓</span></a>
            </div>
        </section>


        <!-- PLATFORM CATEGORIES -->
        <section class="category-strip" aria-label="StudentLink categories">
            <div class="container category-inner">
                <span class="category-label">YOUR CAMPUS TOOLKIT</span>
                <span>01. Resources</span>
                <span>02. Study groups</span>
                <span>03. Items</span>
                <span>04. Services</span>
            </div>
        </section>


        <!-- ABOUT SECTION -->
        <section class="section about-section" id="about">
            <div class="container about-grid">

                <div class="section-intro">
                    <span class="section-label">
                        <span>01</span> ABOUT STUDENTLINK
                    </span>

                    <h2>
                        College is a journey.<br>
                        <span>You don't have to go alone.</span>
                    </h2>
                </div>

                <div class="about-content">
                    <p>
                        StudentLink is a student-centered platform designed
                        to bring useful academic resources, student services,
                        study groups, and item sharing into one convenient space.
                    </p>

                    <p>
                        Instead of searching in different places, students can
                        discover opportunities, share what they have, and connect
                        with people who can help them along the way.
                    </p>

                    <a href="register.php" class="text-link">
                        Become part of the community <span>↗</span>
                    </a>
                </div>

            </div>

            <div class="container about-bottom">
                <div class="about-note">
                    <span class="about-symbol">✳</span>
                    <span>Built around the everyday needs of students.</span>
                </div>

                <span class="about-index">STUDENTLINK / ABOUT</span>
            </div>
        </section>


        <!-- FEATURES SECTION -->
        <section class="section features-section" id="features">
            <div class="container">

                <div class="section-heading">
                    <div>
                        <span class="section-label">
                            <span>02</span> WHAT YOU CAN DO
                        </span>

                        <h2>
                            Everything students need.<br>
                            <span>All in one place.</span>
                        </h2>
                    </div>

                    <p>
                        From finding study materials to connecting with
                        fellow students, discover new ways to make your
                        student experience more convenient.
                    </p>
                </div>

                <div class="features-grid">

                    <!-- RESOURCE FEATURE -->
                    <article class="feature-card feature-resource">
                        <div class="feature-card-top">
                            <span class="feature-number">01</span>
                            <span class="feature-icon">↗</span>
                        </div>

                        <div class="feature-card-content">
                            <span class="feature-category">LEARN</span>
                            <h3>Academic resources</h3>
                            <p>
                                Discover notes, reviewers, references, and
                                other materials that can support your studies.
                            </p>
                            <a href="resources.php" class="feature-link">
                                Explore resources <span>→</span>
                            </a>
                        </div>
                    </article>

                    <!-- STUDY GROUP FEATURE -->
                    <article class="feature-card feature-groups">
                        <div class="feature-card-top">
                            <span class="feature-number">02</span>
                            <span class="feature-icon">◎</span>
                        </div>

                        <div class="feature-card-content">
                            <span class="feature-category">CONNECT</span>
                            <h3>Study groups</h3>
                            <p>
                                Connect with students who share your
                                subjects, interests, and learning goals.
                            </p>
                            <a href="groups.php" class="feature-link">
                                Find a study group <span>→</span>
                            </a>
                        </div>
                    </article>

                    <!-- ITEMS FEATURE -->
                    <article class="feature-card feature-items">
                        <div class="feature-card-top">
                            <span class="feature-number">03</span>
                            <span class="feature-icon">↔</span>
                        </div>

                        <div class="feature-card-content">
                            <span class="feature-category">SHARE</span>
                            <h3>Student items</h3>
                            <p>
                                Find items you need or share useful things
                                with other members of the community.
                            </p>
                            <a href="items.php" class="feature-link">
                                Browse items <span>→</span>
                            </a>
                        </div>
                    </article>

                    <!-- SERVICES FEATURE -->
                    <article class="feature-card feature-services">
                        <div class="feature-card-top">
                            <span class="feature-number">04</span>
                            <span class="feature-icon">✳</span>
                        </div>

                        <div class="feature-card-content">
                            <span class="feature-category">GET SUPPORT</span>
                            <h3>Student services</h3>
                            <p>
                                Explore services and discover helpful
                                opportunities offered by fellow students.
                            </p>
                            <a href="services.php" class="feature-link">
                                Discover services <span>→</span>
                            </a>
                        </div>
                    </article>

                </div>
            </div>
        </section>


        <!-- HOW IT WORKS -->
        <section class="section how-section" id="how-it-works">
            <div class="container">

                <div class="section-heading how-heading">
                    <div>
                        <span class="section-label">
                            <span>03</span> HOW IT WORKS
                        </span>

                        <h2>
                            Getting started is<br>
                            <span>simple.</span>
                        </h2>
                    </div>

                    <p>
                        A few simple steps can help you find resources,
                        discover services, and become part of the community.
                    </p>
                </div>

                <div class="steps-grid">

                    <article class="step">
                        <span class="step-number">01</span>
                        <div class="step-line"></div>
                        <h3>Create your account</h3>
                        <p>
                            Sign up and create your own StudentLink account
                            to get started.
                        </p>
                    </article>

                    <article class="step">
                        <span class="step-number">02</span>
                        <div class="step-line"></div>
                        <h3>Explore your options</h3>
                        <p>
                            Browse available resources, study groups,
                            items, and services.
                        </p>
                    </article>

                    <article class="step">
                        <span class="step-number">03</span>
                        <div class="step-line"></div>
                        <h3>Connect and participate</h3>
                        <p>
                            Find what you need, share what you can,
                            and engage with other students.
                        </p>
                    </article>

                </div>

            </div>
        </section>


        <!-- FINAL CALL TO ACTION -->
        <section class="section join-section">
            <div class="container">

                <div class="join-card">

                    <div class="join-content">
                        <span class="join-label">
                            YOUR NEXT CHAPTER STARTS HERE
                        </span>

                        <h2>
                            Better together.<br>
                            <span>That's StudentLink.</span>
                        </h2>

                        <p>
                            Find your resources, meet your people,
                            and make the most of your student journey.
                        </p>

                        <div class="join-actions">
                            <a href="register.php" class="btn btn-primary">
                                Create your account <span>↗</span>
                            </a>

                            <a href="login.php" class="join-login">
                                Already a member? Log in
                            </a>
                        </div>
                    </div>

                    <div class="join-decoration" aria-hidden="true">
                        <div class="join-ring join-ring-one"></div>
                        <div class="join-ring join-ring-two"></div>
                        <span>SL.</span>
                    </div>

                </div>

            </div>
        </section>

    </main>


    <!-- FOOTER -->
    <footer class="landing-footer">
        <div class="container">

            <div class="footer-main">

                <a href="#home" class="brand footer-brand">
                    <span class="brand-mark">S.</span>
                    <span>StudentLink</span>
                </a>

                <p>Share what you know. Discover what you need.</p>

                <div class="footer-links">
                    <a href="#about">About</a>
                    <a href="#features">Features</a>
                    <a href="login.php">Log in</a>
                    <a href="register.php">Sign up</a>
                </div>

            </div>

            <div class="footer-bottom">
                <span>&copy; <?= date('Y') ?> StudentLink. All rights reserved.</span>
                <a href="#home">Back to top ↑</a>
            </div>

        </div>
    </footer>


    <!-- MOBILE NAVIGATION SCRIPT -->
    <script>
        const menuToggle = document.querySelector('.menu-toggle');
        const mobileNav = document.querySelector('.mobile-nav');

        menuToggle.addEventListener('click', function () {
            const isOpen = menuToggle.getAttribute('aria-expanded') === 'true';

            menuToggle.setAttribute('aria-expanded', String(!isOpen));
            mobileNav.classList.toggle('is-open', !isOpen);
        });

        mobileNav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                menuToggle.setAttribute('aria-expanded', 'false');
                mobileNav.classList.remove('is-open');
            });
        });
    </script>

</body>
</html>
