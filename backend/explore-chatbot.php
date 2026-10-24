<?php
/**
 * backend/explore-chatbot.php: glue only.
 *
 * Explore pages used to render a SECOND floating assistant (#exploreChatToggle) on top of the site-wide one
 * (backend/chatbot.php, included by backend/footer.php), so visitors saw two robot buttons stacked at the bottom-right.
 * There is now ONE widget. Including this file only tells it that the page has a destination context
 * (document.body data-explore-dest), so the single panel also offers "Ask about this place" next to "Ask about TourBuddy tours".
 * Include it BEFORE backend/footer.php on the page. It prints nothing.
 */
$GLOBALS['tms_chat_place_context'] = true;
