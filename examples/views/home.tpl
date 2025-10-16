<h1>Hello, {{ name | upper }}</h1>
<p>UnitTest</p>
<ul>
    {% foreach $users as $user %}
    <li>{{ user['name'] }}</li>
    {% endforeach %}
</ul>