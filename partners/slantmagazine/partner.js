document.addEventListener("DOMContentLoaded", function () {
  // Only run on /dvd/ pages
  if (window.location.pathname.indexOf("/dvd/") === -1) return;

  var player = document.querySelector(".instaread-player-slot");
  if (!player) return;

  var img = document.querySelector(".entry-content img.alignleft");
  if (!img) return;

  var imgParagraph = img.closest("p");
  if (!imgParagraph) return;

  var originalParent = player.parentElement;
  var originalNext = player.nextSibling;
  var mq = window.matchMedia("(max-width: 599px)");

  function handleViewport() {
    if (mq.matches) {
      // Mobile: pull image out of <p>, remove float, place player between image and <p>
      imgParagraph.before(img);
      img.after(player);
      img.style.float = "none";
      img.style.display = "block";
      img.style.marginBottom = "10px";
      player.style.clear = "both";
    } else {
      // Desktop: restore everything
      img.style.float = "";
      img.style.display = "";
      img.style.marginBottom = "";
      player.style.clear = "";
      imgParagraph.prepend(img);
      originalParent.insertBefore(player, originalNext);
    }
  }

  handleViewport();
  mq.addEventListener("change", handleViewport);
});
