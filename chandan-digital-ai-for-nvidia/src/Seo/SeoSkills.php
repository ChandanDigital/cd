<?php

declare(strict_types=1);

namespace ChandanDigital\NvidiaAi\Seo;

/**
 * SEO skills: short instruction sets the model follows for SEO tasks.
 *
 * Adapted from the on-page parts of claude-seo by AgriciDaniel
 * (https://github.com/AgricIDaniel/claude-seo), MIT License, Copyright (c) 2026 agricidaniel.
 * The original skills are written for Claude Code and also run scripts and paid SEO data tools;
 * only the writing and checking rules that work inside WordPress are used here, shortened and
 * reworded. See CREDITS.md in the plugin folder for the full licence text.
 *
 * @since 1.2.0
 */
final class SeoSkills
{
    public const SOURCE_URL = 'https://github.com/AgricIDaniel/claude-seo';

    /**
     * All skills, keyed by ID.
     *
     * @return array<string, array{label: string, description: string, source: string, instruction: string}>
     */
    public static function all(): array
    {
        return [
            'seo-page' => [
                'label' => __('On-page SEO', 'chandan-digital-ai-for-nvidia'),
                'description' => __('Titles, meta descriptions, headings, keyword placement and links for one page.', 'chandan-digital-ai-for-nvidia'),
                'source' => 'seo-page',
                'instruction' => <<<'TXT'
SEO SKILL: ON-PAGE SEO
- SEO title: 50 to 60 characters, primary keyword near the start, unique to this page, says what the reader gets. No clickbait, no ALL CAPS, no keyword repeated twice.
- Meta description: 150 to 160 characters, includes the primary keyword naturally, summarises the page's real value and gives a reason to click. It must not repeat the title and must not end on a stock line such as "Learn more now!" or "Try it free today."
- Headings: exactly one H1 that matches the search intent and holds the keyword. H2 to H6 in order, never skipping a level, each one descriptive.
- Keyword use: primary keyword in the title, the H1 and the first 100 words. Use related terms and natural variations. Density stays natural (roughly 1 to 3 percent); never stuff.
- Links: about 3 to 5 relevant internal links per 1,000 words with descriptive anchor text, and a few links to authoritative outside sources where a claim needs support.
- URL slug: short, lowercase, hyphenated, holds the keyword, no dates or parameters.
TXT,
            ],
            'seo-content' => [
                'label' => __('Content quality and E-E-A-T', 'chandan-digital-ai-for-nvidia'),
                'description' => __('Helpful, people-first content with experience, expertise, authority and trust signals.', 'chandan-digital-ai-for-nvidia'),
                'source' => 'seo-content',
                'instruction' => <<<'TXT'
SEO SKILL: CONTENT QUALITY AND E-E-A-T
- Pass Google's Who, How and Why test: it is clear who wrote the page, how it was made, and that it exists to help people rather than to attract search clicks.
- Experience: favour first-hand detail, real examples, process steps and results the site owner can stand behind. Never invent experience, case studies, numbers, quotes or reviews; ask for them as gaps instead.
- Expertise: accurate, specific, correctly sourced claims at the right depth for the reader.
- Authority and trust: cite reputable sources for facts, keep contact details, dates and corrections honest.
- Coverage: answer the searcher's full question. Word count is not a ranking factor; typical coverage floors are about 1,500 words for a blog post, 800 for a service page and 500 for a homepage or location page, but a shorter page that fully answers the query is better than padding.
- Readability: short paragraphs, varied sentences, clear headings, lists where items are parallel.
- Weak-content signs to fix: generic phrasing, no original insight, the same structure as every other page, no author, factual errors.
TXT,
            ],
            'seo-geo' => [
                'label' => __('AI search readiness', 'chandan-digital-ai-for-nvidia'),
                'description' => __('Clear, quotable answers that both Google and AI assistants can use.', 'chandan-digital-ai-for-nvidia'),
                'source' => 'seo-geo',
                'instruction' => <<<'TXT'
SEO SKILL: AI SEARCH READINESS
- Put a direct, two or three sentence answer to the main question near the top of the page.
- Make each section stand on its own: a clear heading, then a short answer, then the detail, so a single passage can be quoted correctly.
- State facts plainly with numbers, names and dates the site can support, and name the source.
- Google's own guidance says no special AI files, extra markup or AI-only rewrites are needed: good, people-first SEO is what AI features use. Do not recommend tricks aimed only at AI systems.
TXT,
            ],
            'seo-links' => [
                'label' => __('Internal linking', 'chandan-digital-ai-for-nvidia'),
                'description' => __('Relevant links between your own posts and pages with descriptive anchor text.', 'chandan-digital-ai-for-nvidia'),
                'source' => 'seo-page, seo-content',
                'instruction' => <<<'TXT'
SEO SKILL: INTERNAL LINKING
- Link only to pages that genuinely help a reader of this page go deeper or take the next step.
- Anchor text is a short phrase (2 to 6 words) that already appears in the content and describes the target page. Never use "click here", "read more" or a bare URL.
- Aim for about 3 to 5 internal links per 1,000 words. Do not link the same target twice, and do not cram links into one paragraph.
- Use only the candidate pages you are given. Never invent a URL.
TXT,
            ],
            'seo-images' => [
                'label' => __('Image SEO', 'chandan-digital-ai-for-nvidia'),
                'description' => __('Alt text, file names and prompts for featured images.', 'chandan-digital-ai-for-nvidia'),
                'source' => 'seo-images, seo-image-gen',
                'instruction' => <<<'TXT'
SEO SKILL: IMAGE SEO
- Alt text describes what is actually in the image in plain words, under about 125 characters. Include the keyword only where it fits naturally. Never start with "image of" or "picture of", never stuff keywords, never write "click here".
- File names are descriptive, lowercase and hyphenated, for example kolkata-digital-marketing-team.jpg, never IMG_1234.jpg.
- Prompts for a generated featured image describe one clear scene that matches the article: subject, setting, mood, lighting and style. Ask for no text, letters, logos or watermarks in the image, no real people's faces, and nothing misleading about the business.
TXT,
            ],
            'seo-schema' => [
                'label' => __('Schema markup', 'chandan-digital-ai-for-nvidia'),
                'description' => __('Valid JSON-LD structured data for the page type.', 'chandan-digital-ai-for-nvidia'),
                'source' => 'seo-schema',
                'instruction' => <<<'TXT'
SEO SKILL: SCHEMA MARKUP
- Use JSON-LD. Pick the type that matches the page: BlogPosting or Article for posts, Service or LocalBusiness for business pages, Product for products, BreadcrumbList for navigation.
- Include every required property and only facts that appear on the page. Never invent ratings, reviews, prices or addresses.
- Do not recommend HowTo markup or FAQ markup for rich results; Google no longer shows those rich results. QAPage suits genuine question-and-answer pages.
- Output valid JSON only, ready to paste.
TXT,
            ],
        ];
    }

    /**
     * One skill's instruction text, or an empty string for an unknown ID.
     *
     * @param string $id Skill ID.
     */
    public static function instruction(string $id): string
    {
        $all = self::all();
        return isset($all[$id]) ? $all[$id]['instruction'] : '';
    }

    /**
     * Joined instructions for several skills.
     *
     * @param list<string> $ids Skill IDs.
     */
    public static function combined(array $ids): string
    {
        $parts = [];
        foreach ($ids as $id) {
            $text = self::instruction($id);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return implode("\n\n", $parts);
    }
}
