document.addEventListener("DOMContentLoaded", function () {
  // --- Configuration ---
  const publicationId = "artofmanliness";
  const contentSelector = ".post-content-column";

  // Set to true to enable detailed console logs for debugging.
  const debuggerEnabled = false;
  // --- End Configuration ---

  const logStyle = "color: #0073aa; font-weight: bold;";

  // Helper function for conditional logging
  const log = (message, style = "", ...args) => {
    if (debuggerEnabled) {
      if (style) console.log(message, style, ...args);
      else console.log(message, ...args);
    }
  };
  const warn = (message) => {
    if (debuggerEnabled) console.warn(message);
  };

  log(
    `%c[Instaread Player] Initializing for publication: ${publicationId}`,
    logStyle
  );

  const mainContent = document.querySelector(contentSelector);

  if (!mainContent) {
    console.error(
      `[Instaread Player] CRITICAL: Main content container ('${contentSelector}') not found. Script will not run.`
    );
    return;
  }
  log("[Instaread Player] SUCCESS: Found main content container:", mainContent);

  const createPlayerElements = () => {
    const wrapper = document.createElement("div");
    wrapper.className = "playerContainer instaread-content-wrapper";
    wrapper.innerHTML = `
            <instaread-player publication="${publicationId}" class="instaread-player">
              <div class="instaread-audio-player" style="box-sizing:border-box;margin:0">
                <iframe id="instaread_iframe" width="100%" height="100%" scrolling="no" frameborder="0" loading="lazy" title="Audio Article" style="display:block" data-pin-nopin="true"></iframe>
              </div>
            </instaread-player>`;
    const script = document.createElement("script");
    script.src = `https://instaread.co/js/instaread.${publicationId}.js?version=${Date.now()}`;
    script.type = "module";
    return { wrapper, script };
  };

  const { wrapper, script } = createPlayerElements();

  // --- NEW, MORE SPECIFIC LOGIC ---
  // Get the very first HTML element inside the content container.
  const firstElement = mainContent.firstElementChild;
  let targetImageElement = null;

  if (firstElement) {
    log("[Instaread Player] Checking first element in content:", firstElement);
    // Case 1: The first element is a <p> tag. Check if it contains an image.
    if (firstElement.tagName === "P" && firstElement.querySelector("img")) {
      log(
        "[Instaread Player] LOGIC: First element is a paragraph containing a featured image."
      );
      targetImageElement = firstElement; // Target the entire paragraph for injection.
    }
  }

  // --- INJECTION DECISION ---
  if (targetImageElement) {
    // A featured image was found at the top. Inject after it.
    log(
      "%c[Instaread Player] Injecting AFTER the featured image element.",
      logStyle
    );
    targetImageElement.after(wrapper, script);
    console.log("[Instaread Player] Injection complete.");
  } else {
    // No featured image at the top. Find the first paragraph with text.
    log(
      "[Instaread Player] LOGIC: No featured image found. Searching for the first paragraph with text."
    );
    let firstTextParagraph = null;
    const allParagraphs = mainContent.querySelectorAll("p");
    for (const p of allParagraphs) {
      if (p.textContent.trim() !== "") {
        firstTextParagraph = p;
        break;
      }
    }

    if (firstTextParagraph) {
      log(
        "%c[Instaread Player] SUCCESS: Found first text paragraph. Injecting BEFORE it.",
        logStyle,
        firstTextParagraph
      );
      firstTextParagraph.before(wrapper, script);
      console.log("[Instaread Player] Injection complete.");
    } else {
      warn(
        "[Instaread Player] WARNING: No paragraphs with text content were found. Falling back to top placement."
      );
      mainContent.prepend(wrapper, script);
      console.log("[Instaread Player] Injection complete.");
    }
  }
});
