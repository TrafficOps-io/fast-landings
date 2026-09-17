@template "Preview example" version=1 description="A simple landing with separate demonstration data for its library screenshot."

@previewData
{
  "title": "Make room for your next idea.",
  "summary": "A calm workspace for ambitious projects. Start small, build something useful, and share it with the world.",
  "button": "Explore the possibilities",
  "accent": "#456950"
}
@endpreviewData

@section content "Content"
@param title String = "Your headline" label="Headline" required
@param summary Text = "Describe your offer here." label="Description"
@param button String = "Learn more" label="Button text"
@param link Url = "https://example.com" label="Button URL"
@param accent Color = "#334155" label="Accent color"
@endsection

@layout
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{title}}</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #f2f0e8; color: #202821; font: 20px/1.6 system-ui, sans-serif; }
    header { padding: 32px 7%; border-bottom: 1px solid #d8dccf; font-size: 15px; letter-spacing: .12em; }
    main { max-width: 1000px; margin: 90px auto; padding: 0 48px; }
    .eyebrow { color: {{accent}}; font-size: 14px; letter-spacing: .16em; text-transform: uppercase; }
    h1 { max-width: 850px; margin: 28px 0; font-size: clamp(44px, 6vw, 88px); font-weight: 550; line-height: 1.08; letter-spacing: -.05em; }
    p { max-width: 660px; color: #62695f; }
    a { display: inline-block; margin-top: 24px; padding: 16px 26px; border-radius: 8px; background: {{accent}}; color: white; text-decoration: none; font-size: 16px; }
  </style>
</head>
<body>
  <header>STUDIO / IDEAS INTO MOTION</header>
  <main>
    <div class="eyebrow">Space to create</div>
    <h1>{{title}}</h1>
    <p>{{summary}}</p>
    <a href="{{link}}">{{button}} →</a>
  </main>
</body>
</html>
@endlayout
