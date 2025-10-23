<?php
// test-rewrite.php
echo "<h2>Información de Apache</h2>";

// Verificar si mod_rewrite está cargado
if (function_exists('apache_get_modules')) {
    $modules = apache_get_modules();
    if (in_array('mod_rewrite', $modules)) {
        echo "✅ mod_rewrite está ACTIVO<br>";
    } else {
        echo "❌ mod_rewrite NO está activo<br>";
    }
    echo "<br>Módulos cargados:<br>" . implode('<br>', $modules);
} else {
    echo "⚠️ No se puede verificar (shared hosting)<br>";
    echo "Intenta acceder a /admin/kino/ y mira si redirige";
}

echo "<br><br><h3>Variables del servidor:</h3>";
echo "REQUEST_URI: " . $_SERVER['REQUEST_URI'] . "<br>";
echo "SCRIPT_NAME: " . $_SERVER['SCRIPT_NAME'] . "<br>";
echo "QUERY_STRING: " . ($_SERVER['QUERY_STRING'] ?? 'vacío') . "<br>";
?>
```

Accede a `https://kinoapp.gt.tc/test-rewrite.php`

---

## 🎯 Prueba Rápida

1. **Intenta acceder directamente**:
```
   https://kinoapp.gt.tc/admin/index.php?client=kino