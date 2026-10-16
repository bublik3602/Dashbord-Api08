import zipfile
import os

def create_archive():
    base_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    zip_path = os.path.join(base_dir, 'hestiacp_dashboard_zavod.zip')
    
    include_files = [
        'Dockerfile',
        'README.md',
        'config.php',
        'config.sample.php',
        'docker-compose.yml',
        'index.php',
        'install.php',
        'login.php',
        'logout.php',
        'semena.php',
        'energo.php',
    ]
    
    include_dirs = [
        'api',
        'assets',
        'cron',
        'database',
        'includes',
    ]
    
    exclude_extensions = ['.pyc', '.git', '.tmp', '.log', '.txt', '.sqlite', '.sqlite-journal']
    exclude_files = [
        'hestiacp_dashboard_zavod.zip',
        'energy_manual_entries.json',
        'changes_history.json',
        'dashboard_demo.sqlite'
    ]
    
    with zipfile.ZipFile(zip_path, 'w', compression=zipfile.ZIP_DEFLATED) as z:
        for f in include_files:
            fp = os.path.join(base_dir, f)
            if os.path.isfile(fp):
                z.write(fp, arcname=f)
                
        for d in include_dirs:
            dp = os.path.join(base_dir, d)
            if os.path.isdir(dp):
                for root, dirs, files in os.walk(dp):
                    for file in files:
                        if any(file.endswith(ext) for ext in exclude_extensions) or file in exclude_files:
                            continue
                        full_path = os.path.join(root, file)
                        rel_path = os.path.relpath(full_path, base_dir).replace('\\', '/')
                        z.write(full_path, arcname=rel_path)
                        
    print(f"Archive successfully created: {zip_path} ({os.path.getsize(zip_path)} bytes)")

if __name__ == '__main__':
    create_archive()
