@section success "Success page"
@param message Text = "Спасибо за заказ, {body.name}! Вам поступит звонок в течение 5 минут на номер {body.phone}." label="Success message"
@endsection
@validation body fallback="/submit-error.html"
  @param phone String required mask="+380 ... ... ..."
  @param name String required min=4
@endvalidation
@layout
<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Спасибо за заказ</title></head>
<body><main><h1>Заявка принята</h1><p>{{message}}</p></main></body>
</html>
@endlayout
