document.addEventListener("DOMContentLoaded", function () {
  // --- Configuration ---
  const publicationId = "artofmanliness";

  // Confirmed from your screenshots, this is the container for article content.
  const contentSelector = ".post-content-column";
  // --- End Configuration ---

  const mainContent = document.querySelector(contentSelector);

  if (!mainContent) {
    console.error(
      "Instaread Player: Main content container (" +
        contentSelector +
        ") not found."
    );
    return;
  }

  /**
   * Creates the player wrapper and script elements.
   * @returns {{wrapper: HTMLElement, script: HTMLElement}}
   */
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
    script.async = true;

    return { wrapper, script };
  };

  // Find the first image within the main content container.
  const firstImage = mainContent.querySelector("img");
  const { wrapper, script } = createPlayerElements();

  if (firstImage && firstImage.parentNode === mainContent) {
    // CASE 1: An image exists as a direct child of the content column.
    // As shown in your first screenshot, inject the player right AFTER the <img> element.
    firstImage.after(wrapper, script);
  } else {
    // CASE 2: No image exists.
    // As shown in your second screenshot, inject the player as the very first
    // element inside the content container.
    mainContent.prepend(wrapper, script);
  }
});
