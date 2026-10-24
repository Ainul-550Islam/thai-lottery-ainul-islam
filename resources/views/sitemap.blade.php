@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
{{-- XML sitemap (FINAL AUDIT #15): canonical public URLs only.

     The image namespace is declared unconditionally so an <image:image> block can
     never appear under an undeclared prefix; a namespace on a urlset with no image
     entries is harmless, a prefixed element without one is invalid XML for Google.
--}}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
@foreach ($entries as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
@if (! empty($entry['lastmod']))
        <lastmod>{{ $entry['lastmod'] }}</lastmod>
@endif
@if (! empty($entry['changefreq']))
        <changefreq>{{ $entry['changefreq'] }}</changefreq>
@endif
@if (! empty($entry['priority']))
        <priority>{{ $entry['priority'] }}</priority>
@endif
@foreach (($entry['images'] ?? []) as $image)
        <image:image>
            <image:loc>{{ $image['loc'] }}</image:loc>
@if (! empty($image['title']))
            <image:title>{{ $image['title'] }}</image:title>
@endif
        </image:image>
@endforeach
    </url>
@endforeach
</urlset>
