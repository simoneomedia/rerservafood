(function(){
  if(!window.WCOF_PM) return;
  const apiRoot = WCOF_PM.root;
  const nonce = WCOF_PM.nonce;
  const headers = { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' };
  const root = document.getElementById('wcof-product-manager');
  if(!root) return;
  function fetchCategories(){
    return fetch(apiRoot + 'products/categories?per_page=100',{headers}).then(r=>r.json());
  }
  function fetchProducts(){
    return fetch(apiRoot + 'products?per_page=100&status=any',{headers}).then(r=>r.json());
  }

  function uploadImage(file){
    const mediaRoot = apiRoot.replace('wc/v3/','wp/v2/');
    const fd = new FormData();
    fd.append('file', file, file.name);
    return fetch(mediaRoot + 'media',{method:'POST', headers:{'X-WP-Nonce': nonce}, body: fd}).then(r=>r.json());
  }

  function render(){
    Promise.all([fetchCategories(), fetchProducts()]).then(function(res){
      const cats = res[0];
      const prods = res[1];
      root.innerHTML='';
      const addCat = document.createElement('button');
      addCat.textContent = '+ Category';
      addCat.className = 'btn btn-add';
      addCat.addEventListener('click', addCategory);
      root.appendChild(addCat);
      cats.forEach(function(cat){
        const catDiv = document.createElement('div');
        catDiv.className='wcof-cat';
        const head = document.createElement('div');
        head.className='wcof-cat-header';
        const title = document.createElement('span');
        title.textContent = cat.name;
        head.appendChild(title);
        const delBtn = document.createElement('button');
        delBtn.textContent='Delete';
        delBtn.className='btn btn-del';
        delBtn.addEventListener('click', function(){ deleteCategory(cat.id, cat.count); });
        head.appendChild(delBtn);
        catDiv.appendChild(head);
        const list = document.createElement('div');
        list.className='wcof-prod-list';
        prods.filter(p=>p.categories.some(c=>c.id===cat.id)).forEach(function(p){
          list.appendChild(productCard(p));
        });
        const addProd = document.createElement('button');
        addProd.textContent='+ Product';
        addProd.className='btn btn-add';
        addProd.addEventListener('click', function(){ openForm(null, cat.id); });
        list.appendChild(addProd);
        catDiv.appendChild(list);
        root.appendChild(catDiv);
      });
    });
  }

  function addCategory(){
    const name = prompt('Category name');
    if(!name) return;
    fetch(apiRoot+'products/categories',{method:'POST',headers,body:JSON.stringify({name:name})}).then(render);
  }

  function deleteCategory(id,count){
    if(count>0){ alert('Category not empty'); return; }
    if(!confirm('Delete this category?')) return;
    fetch(apiRoot+'products/categories/'+id+'?force=true',{method:'DELETE',headers}).then(render);
  }

  function productCard(p){
    const div = document.createElement('div');
    div.className='wcof-prod';

    const title = document.createElement('div');
    title.className='wcof-prod-title';
    title.textContent = p.name + ' - ' + p.price;

    const active = document.createElement('div');
    active.className='wcof-active';
    const toggle = document.createElement('label');
    toggle.className='wcof-switch';
    const cb = document.createElement('input');
    cb.type='checkbox';
    cb.checked = p.status === 'publish';
    cb.addEventListener('change', function(){
      const status = cb.checked ? 'publish' : 'draft';
      fetch(apiRoot+'products/'+p.id,{method:'PUT',headers,body:JSON.stringify({status})}).then(render);
    });
    const span = document.createElement('span'); span.className='wcof-slider';
    toggle.appendChild(cb);
    toggle.appendChild(span);
    active.appendChild(toggle);
    const activeTxt = document.createElement('span'); activeTxt.textContent='Active';
    active.appendChild(activeTxt);

    const edit = document.createElement('button');
    edit.textContent='Edit';
    edit.className='btn btn-edit';
    edit.addEventListener('click', function(){ openForm(p); });

    div.appendChild(title);
    div.appendChild(active);
    div.appendChild(edit);
    return div;
  }

  function openForm(product, defaultCat){
    const overlay = document.createElement('div');
    overlay.className='wcof-form-overlay';
    const form = document.createElement('form');
    form.className='wcof-prod-form';

    const name = document.createElement('input');
    name.type='text';
    name.name='name';
    name.required=true;
    name.placeholder='Name';
    name.value = product ? product.name : '';
    form.appendChild(name);

    const price = document.createElement('input');
    price.type='number';
    price.name='price';
    price.step='0.01';
    price.required=true;
    price.placeholder='Price';
    price.value = product ? product.price : '';
    form.appendChild(price);

    const imgField = document.createElement('div');
    imgField.className='wcof-img-field';
    const imgPreview = document.createElement('img');
    imgPreview.className='wcof-img-preview';
    if(product && product.images[0]){
      imgPreview.src = product.images[0].src;
      imgPreview.style.display='block';
    }
    imgField.appendChild(imgPreview);
    const fileInput = document.createElement('input');
    fileInput.type='file';
    fileInput.accept='image/*';
    fileInput.style.display='none';
    const uploadBtn = document.createElement('button');
    uploadBtn.type='button';
    uploadBtn.textContent='Upload image';
    uploadBtn.className='wcof-upload-btn';
    uploadBtn.addEventListener('click', function(){ fileInput.click(); });
    fileInput.addEventListener('change', function(){
      if(fileInput.files[0]){
        const reader = new FileReader();
        reader.onload = function(e){
          imgPreview.src = e.target.result;
          imgPreview.style.display='block';
        };
        reader.readAsDataURL(fileInput.files[0]);
      }
    });
    imgField.appendChild(uploadBtn);
    imgField.appendChild(fileInput);
    form.appendChild(imgField);

    const desc = document.createElement('textarea');
    desc.name='description';
    desc.placeholder='Description';
    desc.value = product ? product.description.replace(/<[^>]*>/g,'') : '';
    form.appendChild(desc);

    const allerg = document.createElement('textarea');
    allerg.name='allergens';
    allerg.className='wcof-allergens';
    allerg.placeholder='Allergens';
    allerg.value = product ? (product.meta_data.find(m=>m.key==='_allergens')||{}).value : '';
    form.appendChild(allerg);

    const catSel = document.createElement('select');
    catSel.name='category';
    fetchCategories().then(function(cats){
      cats.forEach(function(c){
        const opt = document.createElement('option');
        opt.value=c.id; opt.textContent=c.name;
        if(product && product.categories.some(x=>x.id===c.id)) opt.selected=true;
        if(!product && defaultCat && defaultCat===c.id) opt.selected=true;
        catSel.appendChild(opt);
      });
    });
    form.appendChild(catSel);

    const save = document.createElement('button');
    save.type='submit';
    save.textContent='Save';
    form.appendChild(save);

    const cancel = document.createElement('button');
    cancel.type='button';
    cancel.textContent='Cancel';
    cancel.addEventListener('click', function(){ overlay.remove(); });
    form.appendChild(cancel);

    form.addEventListener('submit', function(e){
      e.preventDefault();
      const body = {
        name: name.value,
        regular_price: price.value,
        description: desc.value,
        categories: [{id: parseInt(catSel.value,10)}],
        images: [],
        meta_data: [{key:'_allergens', value: allerg.value}]
      };
      const method = product ? 'PUT' : 'POST';
      const url = apiRoot + 'products' + (product?'/'+product.id:'');
      function send(){
        fetch(url,{method:method,headers:headers,body:JSON.stringify(body)}).then(function(){
          overlay.remove();
          render();
        });
      }
      if(fileInput.files[0]){
        uploadImage(fileInput.files[0]).then(function(img){
          body.images = [{id: img.id}];
          send();
        });
      } else if(product && product.images[0]){
        body.images = [{id: product.images[0].id}];
        send();
      } else {
        send();
      }
    });

    overlay.appendChild(form);
    document.body.appendChild(overlay);
  }

  render();
})();
