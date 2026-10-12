export const CHAT_BOTTOM_THRESHOLD_PX = 32;

export const isNearScrollBottom = (viewport, threshold = CHAT_BOTTOM_THRESHOLD_PX) => {
  if (!viewport) return false;

  const remaining = Number(viewport.scrollHeight || 0)
    - Number(viewport.scrollTop || 0)
    - Number(viewport.clientHeight || 0);

  return remaining <= Math.max(0, Number(threshold || 0));
};

export const shouldAutoFollow = ({ isFollowing, userScrolledUp = false, activeScroll = false }) => {
  return Boolean(isFollowing) && !userScrolledUp && !activeScroll;
};
