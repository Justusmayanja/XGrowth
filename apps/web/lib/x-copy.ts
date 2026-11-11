export const X_COPY = {
  topicsLede:
    "Conversations people in this feed are already in. A post that already went out is not a topic.",
  topicsEmptyQuiet:
    "Quiet in this niche right now. Mackie17 holds rather than writing about a post that already went out.",
  topicsEmptyDisconnected: "Connect X to see what's moving in this account's feed.",
  topicsCount(n: number) {
    return n === 1
      ? "One conversation people in this feed are already in."
      : `${n} conversations people in this feed are already in.`;
  },
  draftsLede:
    "Waiting for a strong moment or your yes. A tweet written for a live conversation is cleared after two days, so it does not go out late.",
  draftsEmpty: "No X drafts yet.",
  draftsDeskLede: "Waiting for a strong moment or your yes. A tweet for a live conversation is cleared after two days.",
  draftsDeskEmpty: "Nothing waiting. Write from a conversation on the left.",
  composeLede: "Write a post, thread, poll, or quote in this account's voice.",
  composeDraftsTitle: "Drafts",
  composeDraftsHelp: "Highest score first. Look at the numbers, then post the one you want.",
  composeScoreHighest: "Highest",
  composeVisualLabel: "Visual",
  composeVisualHelp:
    "Text only is the default. Mackie17 writes a prompt, then a flyer, photo, or illustration is generated and shown here. The post keeps the take.",
  composeReferenceHelp: "Add a photo and Mackie17 uses it as the reference for the post.",
  composeBrief: "Joins a conversation already in this feed, from this account's point of view.",
  composeSuggestHelp:
    "Suggest starts from conversations in this feed. Posts already on the account shape the voice, not the topic.",
  composeSuggestPlaceholder:
    "Leave this blank and Suggest writes from conversations in this feed. Or add a point that must be included.",
  repliesCircle:
    "People this account already follows, with more reach — who the audience is already paying attention to.",
  queueOpportunities: "Conversations in this account's feed.",
  queueEmpty: "Nothing from this feed yet. Connect X, or open Topics.",
} as const;
