import os

files_to_include = ['.php', '.html', '.js', '.css', '.py', '.sql']
ignore_dirs = ['.git', 'uploads', 'vendor', '.vscode']
output_file = 'all_code.txt'

with open(output_file, 'w', encoding='utf-8') as outfile:
    for root, dirs, files in os.walk('.'):
        dirs[:] = [d for d in dirs if d not in ignore_dirs]
        for file in files:
            if file == os.path.basename(__file__): continue
            
            if any(file.endswith(ext) for ext in files_to_include):
                filepath = os.path.join(root, file)
                if filepath.endswith(output_file): continue
                
                try:
                    with open(filepath, 'r', encoding='utf-8') as infile:
                        content = infile.read()
                        
                    name_upper = file.upper()
                    # Add directory context if not in root
                    rel_dir = os.path.dirname(filepath).replace('.\\', '').replace('.', '')
                    if rel_dir:
                        name_upper = f"{rel_dir}/{name_upper}".upper()
                        
                    if file.endswith('.php') or file.endswith('.html'):
                        outfile.write(f"\n<!-- {name_upper} -->\n")
                    elif file.endswith('.css') or file.endswith('.js'):
                        outfile.write(f"\n/* {name_upper} */\n")
                    elif file.endswith('.py'):
                        outfile.write(f"\n# {name_upper}\n")
                    elif file.endswith('.sql'):
                        outfile.write(f"\n-- {name_upper}\n")
                    else:
                        outfile.write(f"\n// {name_upper}\n")
                        
                    outfile.write(content)
                    outfile.write("\n\n")
                except Exception as e:
                    print(f"Failed to read {filepath}: {e}")

print(f"Successfully consolidated your code into {output_file}")
