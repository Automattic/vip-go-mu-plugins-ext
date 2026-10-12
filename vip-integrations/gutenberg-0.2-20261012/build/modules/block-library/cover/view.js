// packages/block-library/build-module/cover/view.mjs
import { getContext, store } from "@wordpress/interactivity";

// packages/block-library/build-module/utils/reduced-motion.mjs
function prefersReducedMotion() {
  return window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches === true;
}

// packages/block-library/build-module/cover/view.mjs
store(
  "core/cover",
  {
    state: {
      get videoSrc() {
        const { src, reducedMotionSrc } = getContext();
        if (reducedMotionSrc && prefersReducedMotion()) {
          return reducedMotionSrc;
        }
        return src;
      }
    }
  },
  { lock: true }
);
//# sourceMappingURL=view.js.map
