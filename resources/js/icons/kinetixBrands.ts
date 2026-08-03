import type { Component } from 'vue';
import BrandApple from './kinetixBrands/BrandApple.vue';
import BrandBitbucket from './kinetixBrands/BrandBitbucket.vue';
import BrandDiscord from './kinetixBrands/BrandDiscord.vue';
import BrandFacebook from './kinetixBrands/BrandFacebook.vue';
import BrandGeneric from './kinetixBrands/BrandGeneric.vue';
import BrandGithub from './kinetixBrands/BrandGithub.vue';
import BrandGitlab from './kinetixBrands/BrandGitlab.vue';
import BrandGoogle from './kinetixBrands/BrandGoogle.vue';
import BrandMicrosoft from './kinetixBrands/BrandMicrosoft.vue';
import BrandTwitch from './kinetixBrands/BrandTwitch.vue';
import BrandX from './kinetixBrands/BrandX.vue';

/** A known provider's display label, icon component and brand color. */
export interface BrandDefinition {
    label: string;
    icon: Component;
    /** Official brand color; `null` for the multicolor Microsoft mark. */
    color: string | null;
}

/**
 * Registry of bundled brand icons (local SVG components — no runtime icon
 * dependency). Keyed by Socialite provider name. Microsoft is the hand-authored
 * multicolor mark; the rest are single-path `currentColor` glyphs.
 */
export const brands: Record<string, BrandDefinition> = {
    github: { label: 'GitHub', icon: BrandGithub, color: '#181717' },
    google: { label: 'Google', icon: BrandGoogle, color: '#4285F4' },
    microsoft: { label: 'Microsoft', icon: BrandMicrosoft, color: null },
    gitlab: { label: 'GitLab', icon: BrandGitlab, color: '#FC6D26' },
    bitbucket: { label: 'Bitbucket', icon: BrandBitbucket, color: '#0052CC' },
    facebook: { label: 'Facebook', icon: BrandFacebook, color: '#0866FF' },
    x: { label: 'X', icon: BrandX, color: '#000000' },
    twitter: { label: 'X', icon: BrandX, color: '#000000' },
    apple: { label: 'Apple', icon: BrandApple, color: '#000000' },
    discord: { label: 'Discord', icon: BrandDiscord, color: '#5865F2' },
    twitch: { label: 'Twitch', icon: BrandTwitch, color: '#9146FF' },
};

/** The brand definition for a provider key, falling back to a generic glyph. */
export function brandFor(provider: string): BrandDefinition {
    return (
        brands[provider] ?? {
            label: provider.charAt(0).toUpperCase() + provider.slice(1),
            icon: BrandGeneric,
            color: null,
        }
    );
}
