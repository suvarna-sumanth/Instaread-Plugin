document.addEventListener("DOMContentLoaded", function () {
  // --- Configuration ---
  const publicationId = "jcitytimes"; // Update as needed
  const mainContainerSelector = ".main-content";
  const entryContentSelector = ".entry-content";
  const debuggerEnabled = false; // Set to true for detailed logs
  const logStyle = "color: #0073aa; font-weight: bold;";

  // Logger helpers
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

  // Step 1: Find main container
  const mainContainer = document.querySelector(mainContainerSelector);
  if (!mainContainer) {
    console.error(
      `[Instaread Player] CRITICAL: Main container ('${mainContainerSelector}') not found. Script will not run.`
    );
    return;
  }
  log("[Instaread Player] SUCCESS: Found main container:", "", mainContainer);

  // Step 2: Find entry-content inside main container
  const entryContent = mainContainer.querySelector(entryContentSelector);
  if (!entryContent) {
    console.error(
      `[Instaread Player] CRITICAL: Entry content ('${entryContentSelector}') not found inside main container. Script will not run.`
    );
    return;
  }
  log("[Instaread Player] SUCCESS: Found entry-content:", "", entryContent);

  // Step 3: Create player elements
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

  // Step 4: Inject player as the first child of entry-content
  entryContent.prepend(wrapper, script);
  console.log("[Instaread Player] Injection complete.");
});
