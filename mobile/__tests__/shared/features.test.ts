import { afterEach, describe, expect, it, jest } from '@jest/globals';

describe('feature switches', () => {
  const original = process.env.EXPO_PUBLIC_ENABLE_AI;
  afterEach(() => {
    process.env.EXPO_PUBLIC_ENABLE_AI = original;
    jest.resetModules();
  });

  it('keeps the paid AI extras off unless the build asks for them', () => {
    delete process.env.EXPO_PUBLIC_ENABLE_AI;
    expect(require('../../src/shared/features').AI_ENABLED).toBe(false);
  });

  it('turns them on only for EXPO_PUBLIC_ENABLE_AI=1', () => {
    process.env.EXPO_PUBLIC_ENABLE_AI = '1';
    expect(require('../../src/shared/features').AI_ENABLED).toBe(true);
    jest.resetModules();
    process.env.EXPO_PUBLIC_ENABLE_AI = 'true';
    expect(require('../../src/shared/features').AI_ENABLED).toBe(false);
  });
});
