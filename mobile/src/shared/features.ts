/**
 * Build-time feature switches.
 *
 * The free build of Home Helper ships with every paid service turned off. The Claude-powered
 * extras (AI Hub chat, daily nudges, meal photos, receipt reading, gift ideas) need an
 * Anthropic API key, which costs money per request, so they stay hidden unless the build sets
 * EXPO_PUBLIC_ENABLE_AI=1. Everything else works without them: manual entry, the rule-based
 * recipes, predictions, budget forecasts, chore learning and the hub's joined-up tips.
 */
export const AI_ENABLED = process.env.EXPO_PUBLIC_ENABLE_AI === '1';
