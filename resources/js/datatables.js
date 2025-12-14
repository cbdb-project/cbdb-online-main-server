/**
 * DataTables Entry Point
 *
 * 为需要 DataTables 的页面提供构建入口
 */

// Ensure DataTables gets a jQuery instance even if app.js hasn't run yet
import './jquery-global';

// Import DataTables (Bootstrap 4 styling) — it will attach to the shared jQuery instance
import 'datatables.net-bs4';
import 'datatables.net-bs4/css/dataTables.bootstrap4.min.css';
