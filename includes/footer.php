
<?php if (!($bare ?? false)): ?>

<footer class="sl-foot" aria-label="Site footer">

    <div class="sl-grid">

        <!-- BRAND -->
        <div class="sl-tile sl-brand">
            <div class="sl-brand-top">
                <span class="sl-brand-label">STUDENT COMMUNITY</span>
                <span class="sl-brand-symbol" aria-hidden="true">↗</span>
            </div>

            <p class="sl-tag">
                Share your notes.<br>
                Find your people.
            </p>

            <div class="sl-brand-bottom">
                <span class="sl-word">STUDENTLINK<span>.</span></span>
            </div>

            <div class="sl-orbit sl-orbit-one"></div>
            <div class="sl-orbit sl-orbit-two"></div>
        </div>

        <!-- EXPLORE -->
        <nav class="sl-tile sl-explore" aria-label="Explore">
            <div class="sl-section-head">
                <span class="sl-number">01</span>
                <h2>Explore</h2>
            </div>

            <a href="resources.php">Resources <span>↗</span></a>
            <a href="groups.php">Study groups <span>↗</span></a>
            <a href="items.php">Items <span>↗</span></a>
            <a href="services.php">Services <span>↗</span></a>
        </nav>

        <!-- YOUR SPACE -->
        <nav class="sl-tile sl-space" aria-label="Your space">
            <div class="sl-section-head">
                <span class="sl-number">02</span>
                <h2>Your space</h2>
            </div>

            <a href="profile.php">Profile <span>↗</span></a>
            <a href="activity.php">My activity <span>↗</span></a>
            <a href="transactions.php">Transactions <span>↗</span></a>
            <a href="messages.php">Messages <span>↗</span></a>
        </nav>

        <!-- COMMUNITY -->
        <div class="sl-tile sl-community">
            <span class="sl-community-label">OUR COMMUNITY</span>
            <p>Learn and grow together.</p>

            <a href="resources.php" class="sl-community-link">
                Explore resources <span>→</span>
            </a>
        </div>

        <!-- BACK TO TOP -->
        <div class="sl-tile sl-top">
            <div>
                <span class="sl-top-label">KEEP EXPLORING</span>
                <p>There's more to discover.</p>
            </div>

            <button
                type="button"
                class="sl-top-button"
                onclick="window.scrollTo({top: 0, behavior: 'smooth'});"
                aria-label="Back to top"
            >↑</button>
        </div>

    </div>

    <!-- COPYRIGHT -->
    <div class="sl-bar">
        <span>&copy; <?= date('Y') ?> StudentLink</span>
        <span>Built for the student community</span>
    </div>

</footer>

<style>
/* =========================================
   STUDENTLINK — SMALL COLUMN FOOTER
========================================= */

.sl-foot {
    grid-column: 2;
    min-width: 0;
    margin-top: 22px;
    padding: 0 6px 16px;
    color: var(--text, #ece9e2);
    font-family: var(--f-body, 'Source Sans 3', system-ui, sans-serif);
    font-size: .78rem;
}

.sl-foot,
.sl-foot * {
    box-sizing: border-box;
}

.sl-foot a {
    color: inherit;
    text-decoration: none;
}

/* COLUMN LAYOUT */

.sl-grid {
    display: grid;
    grid-template-columns: 1.2fr 1fr 1fr;
    grid-template-areas:
        "brand explore space"
        "brand community top";
    gap: 9px;
}

.sl-tile {
    position: relative;
    min-width: 0;
    overflow: hidden;
    padding: 16px;
    border: 1px solid rgba(128, 128, 128, .12);
    border-radius: 13px;
}

/* BRAND */

.sl-brand {
    grid-area: brand;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-height: 220px;
    isolation: isolate;
    background: var(--orange, #e45b22);
    color: var(--ink, #161713);
    border-color: transparent;
}

.sl-brand-top,
.sl-brand-bottom {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.sl-brand-label {
    font-size: .55rem;
    font-weight: 800;
    letter-spacing: .09em;
}

.sl-brand-symbol {
    display: grid;
    place-items: center;
    width: 24px;
    height: 24px;
    border: 1px solid rgba(22, 23, 19, .25);
    border-radius: 50%;
    font-size: .85rem;
}

.sl-tag {
    position: relative;
    z-index: 2;
    margin: 20px 0;
    font-size: clamp(.9rem, 1.3vw, 1.05rem);
    font-weight: 750;
    line-height: 1.35;
    letter-spacing: -.025em;
}

.sl-word {
    font-family: var(--f-head, 'Big Shoulders Display', Impact, sans-serif);
    font-size: clamp(1.7rem, 3vw, 2.65rem);
    font-weight: 900;
    line-height: .95;
    letter-spacing: -.045em;
    white-space: nowrap;
}

.sl-word span {
    color: var(--stone, #ece9e2);
}

/* SUBTLE BRAND DETAIL */

.sl-orbit {
    position: absolute;
    z-index: -1;
    width: 105px;
    height: 105px;
    border: 1px solid rgba(22, 23, 19, .16);
    border-radius: 50%;
    pointer-events: none;
}

.sl-orbit-one {
    top: 33%;
    right: -62px;
}

.sl-orbit-two {
    top: calc(33% + 12px);
    right: -50px;
    width: 82px;
    height: 82px;
}

/* NAVIGATION COLUMNS */

.sl-explore {
    grid-area: explore;
    background: var(--panel, #1e1f19);
}

.sl-space {
    grid-area: space;
    background: var(--stone, #ece9e2);
    color: var(--ink, #161713);
}

.sl-section-head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.sl-number {
    display: grid;
    place-items: center;
    width: 21px;
    height: 21px;
    flex-shrink: 0;
    border-radius: 6px;
    background: rgba(128, 128, 128, .13);
    font-size: .56rem;
    font-weight: 800;
}

.sl-section-head h2 {
    margin: 0;
    font-size: .66rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.sl-explore a,
.sl-space a {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    padding: 7px 0;
    border-bottom: 1px solid rgba(128, 128, 128, .16);
    font-size: .77rem;
    font-weight: 550;
    transition: color .18s ease;
}

.sl-explore a:last-child,
.sl-space a:last-child {
    border-bottom: 0;
}

.sl-explore a span,
.sl-space a span {
    opacity: 0;
    transition: opacity .18s ease;
}

.sl-explore a:hover,
.sl-explore a:focus-visible {
    color: var(--orange, #e45b22);
}

.sl-space a:hover,
.sl-space a:focus-visible {
    color: var(--orange-lo, #b13700);
}

.sl-explore a:hover span,
.sl-space a:hover span,
.sl-explore a:focus-visible span,
.sl-space a:focus-visible span {
    opacity: 1;
}

/* COMMUNITY */

.sl-community {
    grid-area: community;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 12px;
    background: var(--stone, #ece9e2);
    color: var(--ink, #161713);
}

.sl-community-label,
.sl-top-label {
    font-size: .54rem;
    font-weight: 800;
    letter-spacing: .09em;
    opacity: .65;
}

.sl-community p,
.sl-top p {
    margin: 7px 0;
    font-size: .88rem;
    font-weight: 700;
    line-height: 1.35;
}

.sl-community-link {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    padding-top: 9px;
    border-top: 1px solid rgba(22, 23, 19, .13);
    font-size: .68rem;
    font-weight: 700;
}

.sl-community-link span {
    transition: transform .18s ease;
}

.sl-community-link:hover span {
    transform: translateX(3px);
}

/* BACK TO TOP */

.sl-top {
    grid-area: top;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    background: var(--black, #0f100d);
}

.sl-top p {
    margin-bottom: 0;
    font-size: .82rem;
}

.sl-top-button {
    display: grid;
    place-items: center;
    width: 31px;
    height: 31px;
    flex-shrink: 0;
    border: 1px solid rgba(128, 128, 128, .2);
    border-radius: 9px;
    background: var(--orange, #e45b22);
    color: var(--ink, #161713);
    font-size: 1rem;
    cursor: pointer;
    transition: background .18s ease;
}

.sl-top-button:hover {
    background: var(--orange-hi, #ff7430);
}

/* COPYRIGHT BAR */

.sl-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    padding: 12px 3px 0;
    color: var(--muted, #a6a89b);
    font-size: .66rem;
}

/* TABLET */

@media (max-width: 980px) {
    .sl-foot {
        grid-column: auto;
        padding-right: 0;
        padding-left: 0;
    }

    .sl-grid {
        grid-template-columns: 1fr 1fr;
        grid-template-areas:
            "brand brand"
            "explore space"
            "community top";
    }

    .sl-brand {
        min-height: 190px;
    }

    .sl-word {
        font-size: 2.5rem;
    }
}

/* MOBILE */

@media (max-width: 600px) {
    .sl-grid {
        grid-template-columns: minmax(0, 1fr);
        grid-template-areas:
            "brand"
            "explore"
            "space"
            "community"
            "top";
        gap: 8px;
    }

    .sl-tile {
        padding: 15px;
        border-radius: 12px;
    }

    .sl-brand {
        min-height: 190px;
    }

    .sl-word {
        font-size: 2.5rem;
    }

    .sl-community,
    .sl-top {
        min-height: 95px;
    }

    .sl-bar {
        align-items: flex-start;
        flex-direction: column;
        gap: 6px;
    }
}

/* REDUCED MOTION */

@media (prefers-reduced-motion: reduce) {
    .sl-foot * {
        transition: none !important;
    }
}
</style>

<?php endif; ?>
</body>
</html>
